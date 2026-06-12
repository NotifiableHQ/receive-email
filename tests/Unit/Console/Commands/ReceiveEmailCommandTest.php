<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Notifiable\ReceiveEmail\Console\Commands\ReceiveEmailCommand;
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;
use Notifiable\ReceiveEmail\Data\Envelope;
use Notifiable\ReceiveEmail\Enums\Source;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Events\EmailRejected;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Models\Email;
use Notifiable\ReceiveEmail\Support\Testing\FakeParsedMail;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Run the command against an in-memory input stream instead of php://stdin,
 * with $arguments standing in for the envelope argv Postfix appends.
 *
 * @param  array<string, string|string[]>  $arguments
 */
function runReceiveEmailCommand(string $input = "Subject: Test\r\n\r\nHello", array $arguments = []): int
{
    $stream = fopen('php://memory', 'r+');

    assert($stream !== false);

    fwrite($stream, $input);
    rewind($stream);

    $command = new class($stream) extends ReceiveEmailCommand
    {
        /**
         * @param  resource  $stream
         */
        public function __construct(private $stream)
        {
            parent::__construct();
        }

        protected function inputStream()
        {
            return $this->stream;
        }
    };

    $command->setLaravel(app());

    return $command->run(new ArrayInput($arguments), new NullOutput);
}

/**
 * A FakeParsedMail that records the contents handed to source().
 */
function recordingParsedMailFake(): FakeParsedMail
{
    return new class extends FakeParsedMail
    {
        public ?string $sourceContents = null;

        public function source($source, Source $type = Source::Stream): ParsedMailContract
        {
            $this->sourceContents = is_resource($source) ? (string) stream_get_contents($source) : $source;

            return $this;
        }
    };
}

it('processes normal mail and exits EX_OK', function () {
    Event::fake();

    ParsedMail::fake([
        'stored' => true,
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $exitCode = runReceiveEmailCommand();

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK);
    Event::assertDispatched(EmailReceived::class);
});

it('passes the full input through to the parser unchanged', function () {
    Event::fake();

    $fake = recordingParsedMailFake()->fake([
        'stored' => true,
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);
    ParsedMail::swap($fake);

    $input = "Subject: Test\r\n\r\nHello";
    $exitCode = runReceiveEmailCommand($input);

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK)
        ->and($fake->sourceContents)->toBe($input);
    Event::assertDispatched(EmailReceived::class);
});

it('records the envelope handed to the pipe as argv arguments on the Email row', function () {
    Event::fake();

    ParsedMail::fake([
        'stored' => true,
        'to' => [['address' => 'header-to@example.com', 'display' => 'Header To']],
    ]);

    $exitCode = runReceiveEmailCommand(arguments: [
        'sender' => 'envelope-sender@example.com',
        'client_address' => '203.0.113.7',
        'queue_id' => '4cVqkW1lq8z2Xw1',
        'recipient' => ['envelope-recipient@example.com'],
    ]);

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK);

    $email = Email::query()->sole();

    expect($email->envelope_sender)->toBe('envelope-sender@example.com')
        ->and($email->envelope_recipients)->toBe(['envelope-recipient@example.com'])
        ->and($email->client_address)->toBe('203.0.113.7')
        ->and($email->queue_id)->toBe('4cVqkW1lq8z2Xw1');

    Event::assertDispatched(EmailReceived::class);
});

it('stores a null Envelope Sender for MAIL FROM:<>', function () {
    Event::fake();

    ParsedMail::fake([
        'stored' => true,
        'to' => [['address' => 'header-to@example.com', 'display' => 'Header To']],
    ]);

    // The pipe's null_sender= attribute passes MAIL FROM:<> as an empty
    // argv string; the row must carry null, never a literal address.
    $exitCode = runReceiveEmailCommand(arguments: [
        'sender' => '',
        'client_address' => '203.0.113.7',
        'queue_id' => '4cVqkW1lq8z2Xw1',
        'recipient' => ['envelope-recipient@example.com'],
    ]);

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK)
        ->and(Email::query()->sole()->envelope_sender)->toBeNull();
});

it('stores every Envelope Recipient of a multi-recipient delivery on one row', function () {
    Event::fake();

    ParsedMail::fake([
        'stored' => true,
        'to' => [['address' => 'header-to@example.com', 'display' => 'Header To']],
    ]);

    // ${recipient} expands to one argv argument per Envelope Recipient;
    // message-scoped rows keep one delivery = one row with all of them.
    $exitCode = runReceiveEmailCommand(arguments: [
        'sender' => 'envelope-sender@example.com',
        'client_address' => '203.0.113.7',
        'queue_id' => '4cVqkW1lq8z2Xw1',
        'recipient' => ['one@example.com', 'two@example.com', 'three@example.com'],
    ]);

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK);

    // sole() doubles as the one-row assertion.
    expect(Email::query()->sole()->envelope_recipients)
        ->toBe(['one@example.com', 'two@example.com', 'three@example.com']);
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

    $exitCode = runReceiveEmailCommand();

    // Discard: never EX_NOHOST (68), which would ask Postfix for a bounce
    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK);
    Event::assertDispatched(EmailRejected::class);
    Event::assertNotDispatched(EmailReceived::class);
});

it('discards rejected mail with EX_OK even when an EmailRejected listener throws', function () {
    // No Event::fake() here: the throwing listener must actually run.
    Log::spy();

    $dispatched = 0;
    Event::listen(EmailRejected::class, function () use (&$dispatched) {
        $dispatched++;

        throw new RuntimeException('Listener failure.');
    });

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

    $exitCode = runReceiveEmailCommand();

    // Discard: tempfail would have Postfix redeliver — and re-reject — the
    // message on every retry until queue expiry.
    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK)
        ->and($dispatched)->toBe(1);
    Log::shouldHaveReceived('error')->once();
});

it('exits EX_TEMPFAIL when a filter fails unexpectedly', function () {
    Event::fake();
    Log::spy();

    // Create a mock filter that fails like a transient outage would
    $throwingFilter = new class implements EmailFilterContract
    {
        public function filter(ParsedMailContract $parsedMail): bool
        {
            throw new RuntimeException('Database is down.');
        }
    };

    $filterClass = get_class($throwingFilter);
    app()->instance($filterClass, $throwingFilter);
    Config::set('receive_email.email-filters', [$filterClass]);

    ParsedMail::fake([
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $exitCode = runReceiveEmailCommand();

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_TEMPFAIL);
    Event::assertNotDispatched(EmailRejected::class);
    Event::assertNotDispatched(EmailReceived::class);
    Log::shouldHaveReceived('error')->once();
});

it('discards malformed mail with EX_OK and logs the discard', function () {
    Event::fake();
    Log::spy();

    ParsedMail::fake([
        'sender' => fn () => throw MalformedMailException::missingSender(),
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $exitCode = runReceiveEmailCommand();

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
        public function handle(ParsedMailContract $parsedMail, Envelope $envelope): void
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

    $exitCode = runReceiveEmailCommand();

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

    $exitCode = runReceiveEmailCommand();

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_TEMPFAIL);
    Log::shouldHaveReceived('error')->once();
});

it('exits EX_TEMPFAIL without parsing when input exceeds the message-size-limit', function () {
    Event::fake();
    Log::spy();
    Config::set('receive_email.message-size-limit', 1024);

    $fake = recordingParsedMailFake();
    ParsedMail::swap($fake);

    $exitCode = runReceiveEmailCommand(str_repeat('a', 1025));

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_TEMPFAIL)
        ->and($fake->sourceContents)->toBeNull();
    Event::assertNotDispatched(EmailReceived::class);
    Event::assertNotDispatched(EmailRejected::class);
    Log::shouldHaveReceived('error')->once();
});

it('processes mail exactly at the message-size-limit', function () {
    Event::fake();
    Config::set('receive_email.message-size-limit', 1024);

    $fake = recordingParsedMailFake()->fake([
        'stored' => true,
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);
    ParsedMail::swap($fake);

    $exitCode = runReceiveEmailCommand(str_repeat('a', 1024));

    expect($exitCode)->toBe(ReceiveEmailCommand::EX_OK)
        ->and(strlen((string) $fake->sourceContents))->toBe(1024);
    Event::assertDispatched(EmailReceived::class);
});
