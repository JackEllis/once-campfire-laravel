<?php

namespace App\Support;

use App\Models\Message;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Renders message partials through a fragment cache, like Rails' `render collection:, cached: true`.
 *
 * Keys carry the message and creator versions, so edits, boosts and profile changes write new
 * fragments instead of invalidating old ones. Mentioned users and room names may lag behind a
 * rename until the message changes, exactly as in Rails. CSRF tokens never enter the cache:
 * fragments are stored split around the renderer's token and joined with the viewer's.
 */
final class MessageFragments
{
    /** Version 1 split fragments on the renderer's real token, which message text could contain. */
    private const VERSION = 2;

    private const TTL = 604800;

    /** Whole message lists are short-lived; their keys already change with every message, boost or rename. */
    private const BLOCK_TTL = 3600;

    /**
     * A whole rendered message list, cached under a version string the caller derives from the
     * ids, `updated_at` and creator `updated_at` of the messages it contains (or of the rooms
     * and users that own them). Misses render through the per-message fragment cache.
     *
     * @param  Closure(): iterable<Message>  $load  Loads the messages on a cache miss.
     */
    public function block(string $version, Closure $load, ?string $token = null): string
    {
        $key = 'block:'.self::VERSION.':'.hash('xxh128', url('/').'|'.$version);
        $cache = $this->cache();
        $parts = $cache->get($key);
        if (! is_array($parts)) {
            $placeholder = self::placeholder();
            $parts = explode($placeholder, $this->render($load(), $placeholder));
            $cache->put($key, $parts, self::BLOCK_TTL);
        }

        return implode($token ?? (string) csrf_token(), $parts);
    }

    /**
     * @param  iterable<Message>  $messages
     * @param  string|null  $token  CSRF token to embed; broadcasts pass '' so no session's token leaks.
     */
    public function render(iterable $messages, ?string $token = null): string
    {
        return implode('', $this->each($messages, $token));
    }

    /**
     * @param  iterable<Message>  $messages
     * @return list<string> HTML for each message, in order.
     */
    public function each(iterable $messages, ?string $token = null): array
    {
        $messages = Collection::make($messages)->values()->loadMissing(['creator', 'room']);
        if ($messages->isEmpty()) {
            return [];
        }

        $keys = $messages->map($this->key(...))->all();
        $fragments = $this->cache()->many($keys);
        $missing = $messages->filter(fn (Message $message, int $i) => ! is_array($fragments[$keys[$i]]));

        if ($missing->isNotEmpty()) {
            $missing->loadMissing(Message::PRESENTATION);
            Collection::make($missing->pluck('room')->filter(fn ($room) => $room?->isDirect())->values())->loadMissing('users');
            $placeholder = self::placeholder();
            $fresh = [];
            $this->withSessionToken($placeholder, function () use ($missing, $keys, $placeholder, &$fresh, &$fragments) {
                foreach ($missing as $i => $message) {
                    $html = view('messages.message', ['message' => $message])->render();
                    $fresh[$keys[$i]] = $fragments[$keys[$i]] = explode($placeholder, $html);
                }
            });
            $this->cache()->putMany($fresh, self::TTL);
        }

        $token ??= (string) csrf_token();

        return array_map(fn (string $key) => implode($token, $fragments[$key]), $keys);
    }

    /**
     * Renders with `csrf_token()` returning the placeholder, so only the generated token fields are
     * split out and message text that happens to contain a real token is left untouched.
     */
    private function withSessionToken(string $placeholder, Closure $render): void
    {
        $session = app('session.store');
        $original = $session->get('_token');
        $session->put('_token', $placeholder);
        try {
            $render();
        } finally {
            $original === null ? $session->forget('_token') : $session->put('_token', $original);
        }
    }

    /** Unguessable, so no message text can contain it. */
    private static function placeholder(): string
    {
        return 'csrf-'.bin2hex(random_bytes(16));
    }

    private function key(Message $message): string
    {
        return implode(':', [
            'message',
            self::VERSION,
            url('/'),
            $message->id,
            $message->getRawOriginal('updated_at'),
            $message->creator?->getRawOriginal('updated_at'),
        ]);
    }

    private function cache(): Repository
    {
        return Cache::store('fragments');
    }
}
