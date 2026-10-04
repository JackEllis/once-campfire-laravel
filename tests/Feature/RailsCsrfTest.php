<?php

namespace Tests\Feature;

use App\Http\Middleware\RailsCsrf;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

final class RailsCsrfTest extends TestCase
{
    public function test_all_rails_csrf_validity_vectors(): void
    {
        $v = json_decode(file_get_contents(base_path('compat/rails_compat.json')), true)['csrf'];
        $guard = new RailsCsrf(app(), app('encrypter'));
        $method = new \ReflectionMethod($guard, 'tokensMatch');
        foreach ($v['validity'] as $row) {
            $r = Request::create($row['path'], $row['method'], ['_token' => $row['token']]);
            $session = new Store('test', new ArraySessionHandler(120));
            $session->start();
            $session->put('_token', $v['session_token']);
            $r->setLaravelSession($session);
            $this->assertSame($row['expected'], $method->invoke($guard, $r), $row['case']);
        }
    }
}
