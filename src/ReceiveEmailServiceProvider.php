<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Support\ServiceProvider;
use Notifiable\ReceiveEmail\Console\Commands\ReceiveEmail;
use Notifiable\ReceiveEmail\Console\Commands\SetupPostfix;
use Notifiable\ReceiveEmail\Facades\ParsedMail;

class ReceiveEmailServiceProvider extends ServiceProvider
{
    public $singletons = [
        ParsedMail::class => ParserParsedMail::class,
    ];

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

        $this->publishesMigrations([
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
