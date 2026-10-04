<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

final class HealthController extends Controller
{
    public function show(Request $request)
    {
        try {
            Event::dispatch(new DiagnosingHealth);
            $status = 'up';
        } catch (\Throwable) {
            $status = 'down';
        }
        $code = $status === 'up' ? 200 : 500;
        if ($request->expectsJson() || $request->is('up.json')) {
            return response()->json(['status' => $status, 'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z')], $code);
        }
        $color = $status === 'up' ? 'green' : 'red';

        return response('<!DOCTYPE html><html><body style="background-color: '.$color.'"></body></html>', $code)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
