<?php

namespace App\Listeners;

use Laravel\Octane\Events\RequestReceived;

final class ForgetCurrentUser
{
    /**
     * Octane keeps one view factory per worker, so the user shared by the
     * previous request would otherwise render on the next guest page.
     */
    public function handle(RequestReceived $event): void
    {
        if ($event->sandbox->resolved('view')) {
            $event->sandbox->make('view')->share('currentUser', null);
        }
    }
}
