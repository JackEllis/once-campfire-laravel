<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SidebarEvents
{
    public function globalRemove(int $roomId): void
    {
        DB::afterCommit(fn () => app(Broadcasts::class)->publish('rooms', '<turbo-stream action="remove" target="list_room_'.$roomId.'"></turbo-stream>'));
    }

    public function refresh(array $userIds): void
    {
        DB::afterCommit(function () use ($userIds) {
            foreach (User::active()->whereIn('id', array_unique($userIds))->where('role', '!=', 2)->get() as $user) {
                [$directs, $shared] = $user->sidebar();
                $html = view('users.sidebar', ['directs' => $directs, 'shared' => $shared, 'currentUser' => $user])->render();
                app(Broadcasts::class)->publish('user_'.$user->id.'_rooms', '<turbo-stream action="replace" target="user_sidebar"><template>'.$html.'</template></turbo-stream>');
            }
        });
    }
}
