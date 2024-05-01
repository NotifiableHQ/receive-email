<?php

namespace Notifiable;

use Illuminate\Support\ServiceProvider;
use Notifiable\Console\Commands\ConfigurePostfix;
use Notifiable\Console\Commands\ReceiveEmail;

class ReceiveEmailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->registerMigrationPublishing();
            $this->registerCommands();
        }
    }

    private function registerMigrationPublishing(): void
    {
        $method = method_exists($this, 'publishesMigrations') ? 'publishesMigrations' : 'publishes';

        $this->{$method}([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['notifiable', 'notifiable-migrations']);
    }

    private function registerCommands(): void
    {
        $this->commands([
            ConfigurePostfix::class,
            ReceiveEmail::class,
        ]);
    }
}
