<?php

namespace Notifiable\ReceiveEmail\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Notifiable\ReceiveEmail\ReceiveEmailServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            ReceiveEmailServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // Drop tables first to ensure a clean state
        Schema::dropIfExists('emails');
        Schema::dropIfExists('senders');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use in-memory SQLite database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Configure package settings
        $app['config']->set('receive_email.storage-disk', 'local');
        $app['config']->set('receive_email.email-table', 'emails');
        $app['config']->set('receive_email.sender-table', 'senders');
    }
}
