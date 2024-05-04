<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Support\ServiceProvider;
use Notifiable\ReceiveEmail\Console\Commands\ReceiveEmail;
use Notifiable\ReceiveEmail\Console\Commands\SetupPostfix;

class ReceiveEmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/notifiable.php', 'notifiable');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/notifiable.php' => config_path('notifiable.php'),
        ], ['notifiable', 'notifiable-config']);

        $method = method_exists($this, 'publishesMigrations') ? 'publishesMigrations' : 'publishes';

        $this->{$method}([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['notifiable', 'notifiable-migrations']);
    }

    private function registerCommands(): void
    {
        $this->commands([
            SetupPostfix::class,
            ReceiveEmail::class,
        ]);
    }
}
