<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Room;
use App\Support\Broadcasts;
use App\Support\ChatEvents;
use App\Support\MessageFragments;
use App\Support\MessageWriter;
use App\Support\RichTextRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChatController extends Controller
{
    private const PAGE = 40;

    public function root(Request $r)
    {
        $room = $r->user()->rooms()->orderByDesc('id')->first();

        return $room ? redirect('/rooms/'.$room->id) : redirect('/rooms/opens/new');
    }

    public function room(Request $r, int $id, ?int $message = null)
    {
        $room = $this->findRoom($r, $id);
        if ($message) {
            $anchor = $room->messages()->findOrFail($message);
            $at = $anchor->getRawOriginal('created_at');
            $messages = $room->messages()->where('created_at', '<', $at)->orderByDesc('created_at')->limit(self::PAGE)->get()->reverse()
                ->push($anchor)
                ->concat($room->messages()->where('created_at', '>', $at)->orderBy('created_at')->limit(self::PAGE)->get());
        } else {
            $messages = $room->messages()->orderByDesc('created_at')->limit(self::PAGE)->get()->reverse();
        }

        return response()->view('rooms.show', compact('room', 'messages'))
            ->withCookie(cookie('last_room', (string) $room->id, 60 * 24 * 365 * 20));
    }

    public function messages(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $query = $room->messages();
        if ($r->filled('before')) {
            $query->where('created_at', '<', $room->messages()->findOrFail($r->input('before'))->getRawOriginal('created_at'));
        }
        if ($r->filled('after')) {
            $after = $room->messages()->findOrFail($r->input('after'))->getRawOriginal('created_at');
            $messages = $query->where('created_at', '>', $after)->orderBy('created_at')->limit(self::PAGE)->get();
        } else {
            $messages = $query->orderByDesc('created_at')->limit(self::PAGE)->get()->reverse();
        }

        if ($messages->isEmpty()) {
            return response('', 204);
        }
        if ($r->expectsJson()) {
            return response()->json($messages->load(Message::PRESENTATION)->map(fn ($m) => $this->json($m))->values());
        }

        return response()->view('messages.index', compact('messages'));
    }

    public function show(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->presentation()->findOrFail($id);

        return $r->expectsJson() ? response()->json($this->json($m)) : view('messages.show', ['message' => $m]);
    }

    public function edit(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->presentation()->findOrFail($id);
        abort_unless($r->user()->canAdminister($m), 403);

        return view('messages.edit', ['message' => $m]);
    }

    public function create(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $attributes = $r->validate([
            'message' => 'required|array',
            'message.body' => 'nullable|string',
            'message.client_message_id' => 'nullable|string|max:255',
            'message.attachment' => 'nullable',
        ])['message'];
        if ($r->hasFile('message.attachment')) {
            $attributes['attachment'] = $r->file('message.attachment');
        }

        $m = app(MessageWriter::class)->create($room, $r->user(), $attributes, true);
        app(ChatEvents::class)->created($m);

        if ($r->expectsJson()) {
            return response()->json($this->json($m->loadMissing(Message::PRESENTATION)), 201);
        }

        return response($this->stream('append', 'messages_room_'.$room->id, app(MessageFragments::class)->render([$m])))
            ->header('Content-Type', 'text/vnd.turbo-stream.html; charset=utf-8');
    }

    public function update(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->findOrFail($id);
        abort_unless($r->user()->canAdminister($m), 403);
        app(MessageWriter::class)->update($m, $r->input('message', []));
        $m->refresh()->load(Message::PRESENTATION);
        app(Broadcasts::class)->room($room, $this->stream('replace', 'presentation_message_'.$m->client_message_id, view('messages.presentation', ['message' => $m])->render()));

        return $r->expectsJson() ? response()->json($this->json($m)) : redirect('/rooms/'.$room.'/messages/'.$id);
    }

    public function destroy(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->findOrFail($id);
        abort_unless($r->user()->canAdminister($m), 403);
        $target = 'message_'.$m->client_message_id;
        app(MessageWriter::class)->destroy($m);
        $s = $this->stream('remove', $target, '');
        app(Broadcasts::class)->room($room, $s);

        return response($s)->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    /**
     * Unscoped message routes that take the room as a `room_id` parameter.
     */
    public function legacy(Request $r, ?int $id = null)
    {
        $room = (int) $r->input('room_id');
        abort_unless($room, 404);

        return match ($r->method()) {
            'GET' => $id ? $this->show($r, $room, $id) : $this->messages($r, $room),
            'POST' => $this->create($r, $room),
            'PATCH', 'PUT' => $this->update($r, $room, $id),
            'DELETE' => $this->destroy($r, $room, $id),
        };
    }

    public function sidebar(Request $r)
    {
        [$directs, $shared] = $r->user()->sidebarMemberships();

        return view('users.sidebar', compact('directs', 'shared'));
    }

    public function search(Request $r)
    {
        $query = preg_replace('/[^\p{L}\p{N}_]/u', ' ', $r->input('q', ''));
        $messages = collect();
        if (trim($query) !== '') {
            $messages = Message::query()
                ->join('message_search_index as idx', 'messages.id', '=', 'idx.rowid')
                ->whereRaw('idx.body MATCH ?', [$query])
                ->whereIn('room_id', $r->user()->rooms()->select('rooms.id'))
                ->select('messages.*')
                ->orderByDesc('messages.created_at')
                ->limit(100)
                ->get()
                ->reverse();
        }

        return view('searches.index', compact('query', 'messages'));
    }

    public function recordSearch(Request $r)
    {
        $query = preg_replace('/[^\p{L}\p{N}_]/u', ' ', $r->input('q', ''));
        DB::table('searches')->updateOrInsert(['user_id' => $r->user()->id, 'query' => $query], ['created_at' => now(), 'updated_at' => now()]);

        return redirect('/searches?q='.urlencode($query));
    }

    public function clearSearch(Request $r)
    {
        DB::table('searches')->where('user_id', $r->user()->id)->delete();

        return redirect('/searches');
    }

    public function refresh(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $since = CarbonImmutable::createFromTimestampMs((int) $r->input('since', 0));
        $new = $room->messages()->where('created_at', '>', $since)->orderBy('created_at')->limit(self::PAGE)->get();
        $updated = $room->messages()->whereNotIn('id', $new->pluck('id'))->where('updated_at', '>', $since)->orderByDesc('created_at')->limit(self::PAGE)->get()->reverse()->values();

        $fragments = app(MessageFragments::class);
        $s = '';
        foreach ($fragments->each($new) as $html) {
            $s .= $this->stream('append', 'messages_room_'.$room->id, $html);
        }
        foreach ($fragments->each($updated) as $i => $html) {
            $s .= $this->stream('replace', 'message_'.$updated[$i]->client_message_id, $html);
        }

        return response($s)->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    public function findRoom(Request $r, int $id): Room
    {
        return $r->user()->rooms()->findOrFail($id);
    }

    public function json(Message $m): array
    {
        return [
            'id' => $m->id,
            'created_at' => $m->created_at->toISOString(),
            'body' => ['plain_text' => $m->plainText(), 'html' => app(RichTextRenderer::class)->html($m->richText?->body ?? '')],
            'creator' => ['id' => $m->creator->id, 'name' => $m->creator->name, 'role' => ['member', 'administrator', 'bot'][$m->creator->role], 'avatar_url' => url($m->creator->avatarUrl())],
            'room' => ['id' => $m->room_id],
            'url' => url('/rooms/'.$m->room_id.'/messages/'.$m->id),
        ];
    }

    public function stream(string $action, string $target, string $html): string
    {
        return '<turbo-stream action="'.e($action).'" target="'.e($target).'"><template>'.$html.'</template></turbo-stream>';
    }
}
