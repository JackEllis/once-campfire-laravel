<?php

namespace App\Support;

final class Broadcasts
{
    public function publish(string $stream, mixed $message): void
    {
        $this->publishMany([[$stream, $message]]);
    }

    /**
     * Append events to the cable outbox in a single locked write.
     *
     * @param  list<array{0: string, 1: mixed}>  $events
     */
    public function publishMany(array $events): void
    {
        $lines = '';
        foreach ($events as [$stream, $message]) {
            $lines .= json_encode(['stream' => $stream, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }
        if ($lines === '') {
            return;
        }

        $file = fopen(config('campfire.events'), 'ab');
        if (! $file) {
            throw new \RuntimeException('Cannot open broadcast outbox');
        }
        try {
            if (! flock($file, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock broadcast outbox');
            }
            for ($written = 0; $written < strlen($lines); $written += $count) {
                $count = fwrite($file, substr($lines, $written));
                if ($count === false || $count === 0) {
                    throw new \RuntimeException('Broadcast outbox write failed');
                }
            }
            fflush($file);
            flock($file, LOCK_UN);
        } finally {
            fclose($file);
        }
    }

    public function room(int $id, string $html): void
    {
        $this->publish('room_'.$id.'_messages', $html);
    }
}
