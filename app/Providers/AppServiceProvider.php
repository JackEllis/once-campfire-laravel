<?php

namespace App\Providers;

use App\Support\Assets;
use App\Support\BlobStorage;
use App\Support\RailsCrypto;
use App\Support\RichTextRenderer;
use App\Support\SQLiteConnection;
use App\Support\SQLiteConnector;
use App\Support\SQLiteGrammar;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('db.connector.sqlite', SQLiteConnector::class);
        Connection::resolverFor('sqlite', fn ($pdo, $database, $prefix, $config) => new SQLiteConnection($pdo, $database, $prefix, $config));
        $this->app->scoped(RichTextRenderer::class);
        $this->app->singleton(Assets::class);
        $this->app->singleton(BlobStorage::class);
        $this->app->singleton(RailsCrypto::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $connection = DB::connection();
        $connection->setQueryGrammar(new SQLiteGrammar($connection));
        if (file_exists(storage_path('vapid.json'))) {
            $keys = json_decode(file_get_contents(storage_path('vapid.json')), true);
            config(['campfire.vapid_public_key' => $keys['publicKey'] ?? '']);
        }
    }
}
