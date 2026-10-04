<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Presence
{
    private function memberships(int $userId, int $roomId)
    {
        return DB::table('memberships')->where('user_id', $userId)->where('room_id', $roomId);
    }

    private function connected(): string
    {
        return 'connected_at >= '.DB::connection()->getPdo()->quote(now()->subMinute()->format('Y-m-d H:i:s.u'));
    }

    public function present(int $userId, int $roomId): void
    {
        $this->memberships($userId, $roomId)->update(['connections' => DB::raw('CASE WHEN '.$this->connected().' THEN connections+1 ELSE 1 END'), 'connected_at' => now(), 'unread_at' => null]);
        app(Broadcasts::class)->publish('user_'.$userId.'_reads', ['room_id' => $roomId]);
    }

    public function refresh(int $userId, int $roomId): void
    {
        $this->memberships($userId, $roomId)->update(['connections' => DB::raw('CASE WHEN '.$this->connected().' THEN connections ELSE 1 END'), 'connected_at' => now()]);
    }

    public function absent(int $userId, int $roomId): void
    {
        $connected = $this->connected();
        $this->memberships($userId, $roomId)->update(['connections' => DB::raw('CASE WHEN '.$connected.' THEN MAX(connections-1,0) ELSE 0 END'), 'connected_at' => DB::raw('CASE WHEN '.$connected.' AND connections>1 THEN connected_at ELSE NULL END'), 'updated_at' => now()]);
    }
}
