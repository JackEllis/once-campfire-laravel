<?php

namespace App\Support;

use App\Jobs\DeliverMessageNotifications;
use App\Models\Message;

final class Notifications
{
    public function message(Message $message, bool $webhooks = false): void
    {
        DeliverMessageNotifications::dispatch($message->id, $webhooks);
    }
}
