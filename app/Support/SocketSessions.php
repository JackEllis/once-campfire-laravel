<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Workerman\Connection\TcpConnection;

final class SocketSessions
{
    public function valid(TcpConnection $connection): bool
    {
        return isset($connection->userId) && DB::table('sessions')->join('users', 'users.id', '=', 'sessions.user_id')->where('sessions.id', $connection->sessionId)->where('users.status', 0)->where('users.role', '!=', 2)->exists();
    }

    public function admitted(TcpConnection $connection, ?float $now = null): bool
    {
        if (! isset($connection->userId)) {
            if (($now ?? microtime(true)) >= $connection->handshakeDeadline) {
                $connection->close();
            }

            return false;
        }
        if (! $this->valid($connection)) {
            $connection->close();

            return false;
        }

        return true;
    }
}
