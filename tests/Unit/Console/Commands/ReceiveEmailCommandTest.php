<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Notifiable\ReceiveEmail\Console\Commands\ReceiveEmailCommand;
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Events\EmailRejected;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;

it('processes normal mail and exits EX_OK', function () {
    Event::fake();

    ParsedMail::fake([
        'stored' => true,
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $exitCode = $this->artisan('notifiable:receive-email')->run();

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK);
    Event::assertDispatched(EmailReceived::class);
});

it('discards filter-rejected mail with EX_OK after dispatching EmailRejected', function () {
    Event::fake();

    // Create a mock filter that rejects the mail
    $failingFilter = new class implements EmailFilterContract
    {
        public function filter(ParsedMailContract $parsedMail): bool
        {
            return false;
        }
    };

    $filterClass = get_class($failingFilter);
    app()->instance($filterClass, $failingFilter);
    Config::set('receive_email.email-filters', [$filterClass]);

    ParsedMail::fake([
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $exitCode = $this->artisan('notifiable:receive-email')->run();

    // Discard: never EX_NOHOST (68), which would ask Postfix for a bounce
    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK);
    Event::assertDispatched(EmailRejected::class);
    Event::assertNotDispatched(EmailReceived::class);
});

it('discards malformed mail with EX_OK and logs the discard', function () {
    Event::fake();
    Log::spy();

    ParsedMail::fake([
        'sender' => fn () => throw MalformedMailException::missingSender(),
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $exitCode = $this->artisan('notifiable:receive-email')->run();

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK);
    Event::assertNotDispatched(EmailReceived::class);
    Log::shouldHaveReceived('warning')->once();
});

it('exits EX_TEMPFAIL when the pipe command fails unexpectedly', function () {
    Event::fake();
    Log::spy();

    // Create a mock pipe command that fails like a transient outage would
    $failingPipeCommand = new class implements PipeCommandContract
    {
        public function handle(ParsedMailContract $parsedMail): void
        {
            throw new RuntimeException('Database is down.');
        }
    };

    $pipeCommandClass = get_class($failingPipeCommand);
    app()->instance($pipeCommandClass, $failingPipeCommand);
    Config::set('receive_email.pipe-command', $pipeCommandClass);

    ParsedMail::fake([
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $exitCode = $this->artisan('notifiable:receive-email')->run();

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_TEMPFAIL);
    Event::assertNotDispatched(EmailReceived::class);
    Log::shouldHaveReceived('error')->once();
});

it('exits EX_TEMPFAIL when the pipe filter is misconfigured', function () {
    Event::fake();
    Log::spy();

    $invalidFilter = new class {};

    $invalidClass = get_class($invalidFilter);
    app()->instance($invalidClass, $invalidFilter);
    Config::set('receive_email.pipe-filter', $invalidClass);

    ParsedMail::fake([
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $exitCode = $this->artisan('notifiable:receive-email')->run();

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_TEMPFAIL);
    Log::shouldHaveReceived('error')->once();
});
