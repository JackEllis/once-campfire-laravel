<?php

namespace App\Support;

use App\Http\Controllers\ChatController;
use App\Models\Message;

final class ChatEvents
{
    public function created(Message $m): void
    {
        $html = app(MessageFragments::class)->render([$m], token: '');
        app(Broadcasts::class)->room($m->room_id, app(ChatController::class)->stream('append', 'messages_room_'.$m->room_id, $html));
        app(Broadcasts::class)->publishMany($m->room->memberships()->pluck('user_id')->map(fn ($id) => ['user_'.$id.'_unreads', ['roomId' => $m->room_id]])->all());
    }
}
