<?php

namespace App\Support;

use App\Models\Message;
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
    private const VERSION = 1;

    private const TTL = 604800;

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
            $renderToken = (string) csrf_token();
            $fresh = [];
            foreach ($missing as $i => $message) {
                $html = view('messages.message', ['message' => $message])->render();
                $fresh[$keys[$i]] = $fragments[$keys[$i]] = $renderToken === '' ? [$html] : explode($renderToken, $html);
            }
            $this->cache()->putMany($fresh, self::TTL);
        }

        $token ??= (string) csrf_token();

        return array_map(fn (string $key) => implode($token, $fragments[$key]), $keys);
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
