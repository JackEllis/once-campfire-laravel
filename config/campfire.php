<?php

use App\Support\RailsCrypto;

return [
    'secret' => env('SECRET_KEY_BASE'),
    'keys' => RailsCrypto::deriveKeys(env('SECRET_KEY_BASE')),
    'files' => env('STORAGE_PATH', storage_path('files')),
    'events' => storage_path('events.log'),
];
