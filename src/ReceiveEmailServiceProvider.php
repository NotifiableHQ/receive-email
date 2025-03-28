<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Support\ServiceProvider;
use Notifiable\ReceiveEmail\Console\Commands\ReceiveEmailCommand;
use Notifiable\ReceiveEmail\Console\Commands\SetupPostfixCommand;
use Notifiable\ReceiveEmail\Facades\ParsedMail;

class ReceiveEmailServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string,class-string>
     */
    public $bindings = [
        ParsedMail::class => ParserParsedMail::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/receive_email.php', 'receive_email');
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
            __DIR__.'/../config/receive_email.php' => config_path('receive_email.php'),
        ], ['receive-email', 'receive-email-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['receive-email', 'receive-email-migrations']);
    }

    private function registerCommands(): void
    {
        $this->commands([
            SetupPostfixCommand::class,
            ReceiveEmailCommand::class,
        ]);
    }
}
