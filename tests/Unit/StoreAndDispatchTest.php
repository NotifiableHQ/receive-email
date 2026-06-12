<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Envelope;
use Notifiable\ReceiveEmail\Enums\Source;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Events\MalformedEmailReceived;
use Notifiable\ReceiveEmail\Exceptions\FailedToStoreException;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Models\Email;
use Notifiable\ReceiveEmail\Models\Sender;
use Notifiable\ReceiveEmail\StoreAndDispatch;

beforeEach(function () {
    Storage::fake('local');
});

it('stores the raw message and enriches the committed row', function () {
    Event::fake();

    $messageId = '<test-id@example.com>';
    $date = CarbonImmutable::now();

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn($messageId);
    $mockParsedMail->shouldReceive('date')->andReturn($date);
    $mockParsedMail->shouldReceive('sender')->andReturn(new Address('sender@example.com', 'Sender Name'));
    $mockParsedMail->shouldReceive('store')->once()->andReturnUsing(function (string $path) {
        Storage::disk('local')->put($path, 'raw message');

        return true;
    });

    (new StoreAndDispatch)->handle($mockParsedMail, new Envelope(
        'envelope-sender@example.com',
        ['envelope-recipient@example.com'],
        '203.0.113.7',
        '4cVqkW1lq8z2Xw1',
    ));

    $this->assertDatabaseHas('senders', [
        'address' => 'sender@example.com',
        'display' => 'Sender Name',
    ]);

    $email = Email::query()->sole();

    expect($email->envelope_sender)->toBe('envelope-sender@example.com')
        ->and($email->envelope_recipients)->toBe(['envelope-recipient@example.com'])
        ->and($email->client_address)->toBe('203.0.113.7')
        ->and($email->queue_id)->toBe('4cVqkW1lq8z2Xw1')
        ->and($email->message_id)->toBe($messageId)
        ->and($email->sent_at->toDateTimeString())->toBe($date->toDateTimeString())
        ->and($email->parsed_at)->not->toBeNull()
        ->and($email->sender?->address)->toBe('sender@example.com');

    Storage::disk('local')->assertExists($email->path());

    Event::assertDispatched(EmailReceived::class, function ($event) use ($email) {
        return $event->email->is($email);
    });
});

it('stores two deliveries bearing the same Message-ID', function () {
    Event::fake();

    $messageId = '<duplicate-id@example.com>';

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn($messageId);
    $mockParsedMail->shouldReceive('date')->andReturn(CarbonImmutable::now());
    $mockParsedMail->shouldReceive('sender')->andReturn(new Address('sender@example.com', 'Sender Name'));
    $mockParsedMail->shouldReceive('store')->twice()->andReturn(true);

    $storeAndDispatch = new StoreAndDispatch;
    $storeAndDispatch->handle($mockParsedMail, new Envelope);
    $storeAndDispatch->handle($mockParsedMail, new Envelope);

    expect(Email::where('message_id', $messageId)->count())->toBe(2);

    Event::assertDispatchedTimes(EmailReceived::class, 2);
});

it('stores the raw message under the row path before the row exists, outside any transaction', function () {
    Event::fake();

    $baseTransactionLevel = DB::transactionLevel();
    $rowsAtStoreTime = null;
    $levelAtStoreTime = null;
    $storedPath = null;

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn('<order-test@example.com>');
    $mockParsedMail->shouldReceive('date')->andReturn(CarbonImmutable::now());
    $mockParsedMail->shouldReceive('sender')->andReturn(new Address('sender@example.com', 'Sender'));
    $mockParsedMail->shouldReceive('store')->once()->andReturnUsing(
        function (string $path) use (&$rowsAtStoreTime, &$levelAtStoreTime, &$storedPath) {
            $storedPath = $path;
            $rowsAtStoreTime = Email::query()->count();
            $levelAtStoreTime = DB::transactionLevel();

            Storage::disk('local')->put($path, 'raw message');

            return true;
        }
    );

    (new StoreAndDispatch)->handle($mockParsedMail, new Envelope);

    expect($rowsAtStoreTime)->toBe(0)
        ->and($levelAtStoreTime)->toBe($baseTransactionLevel)
        ->and(Email::query()->sole()->path())->toBe($storedPath);
});

it('keeps Malformed Mail: raw file and envelope row survive with parsed_at null', function () {
    Event::fake();

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andThrow(MalformedMailException::missingHeader('message-id'));
    $mockParsedMail->shouldReceive('store')->once()->andReturnUsing(function (string $path) {
        Storage::disk('local')->put($path, 'raw message');

        return true;
    });

    (new StoreAndDispatch)->handle($mockParsedMail, new Envelope(
        'envelope-sender@example.com',
        ['envelope-recipient@example.com'],
    ));

    $email = Email::query()->sole();

    expect($email->envelope_sender)->toBe('envelope-sender@example.com')
        ->and($email->envelope_recipients)->toBe(['envelope-recipient@example.com'])
        ->and($email->message_id)->toBeNull()
        ->and($email->sent_at)->toBeNull()
        ->and($email->parsed_at)->toBeNull()
        ->and($email->sender)->toBeNull()
        ->and(Sender::query()->count())->toBe(0);

    Storage::disk('local')->assertExists($email->path());

    Event::assertDispatched(MalformedEmailReceived::class, function ($event) use ($email) {
        return $event->email->is($email);
    });
    Event::assertNotDispatched(EmailReceived::class);
});

it('keeps mail whose Date header is present but unparseable as Malformed Mail', function () {
    Event::fake();

    $raw = "Message-ID: <bad-date@example.com>\r\n"
        ."Date: not a date\r\n"
        ."From: Sender Name <sender@example.com>\r\n"
        ."To: recipient@example.com\r\n"
        ."Subject: Bad date\r\n"
        ."\r\n"
        .'Body';

    (new StoreAndDispatch)->handle(ParsedMail::source($raw, Source::Text), new Envelope(
        'envelope-sender@example.com',
        ['envelope-recipient@example.com'],
    ));

    // Unparseable Date is Malformed Mail: kept and announced, never
    // tempfailed into Postfix's multi-day retry loop.
    $email = Email::query()->sole();

    expect($email->message_id)->toBeNull()
        ->and($email->sent_at)->toBeNull()
        ->and($email->parsed_at)->toBeNull()
        ->and($email->sender)->toBeNull();

    // The stored raw message must round-trip non-empty: a regression to
    // writing the text-source parser's (null) stream can never pass this.
    $stored = Storage::disk('local')->get($email->path());

    expect($stored)->toContain('Message-ID: <bad-date@example.com>')
        ->and($stored)->toContain('Body');

    Event::assertDispatched(MalformedEmailReceived::class, fn ($event) => $event->email->is($email));
    Event::assertNotDispatched(EmailReceived::class);
})->skip(! extension_loaded('mailparse'), 'Requires mailparse extension');

it('throws FailedToStoreException when the storage write returns false', function () {
    Event::fake();

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('store')->once()->andReturn(false);

    expect(fn () => (new StoreAndDispatch)->handle($mockParsedMail, new Envelope))
        ->toThrow(FailedToStoreException::class);

    expect(Email::query()->count())->toBe(0)
        ->and(Sender::query()->count())->toBe(0);

    Event::assertNotDispatched(EmailReceived::class);
    Event::assertNotDispatched(MalformedEmailReceived::class);
});

it('propagates a storage write failure without touching the database', function () {
    Event::fake();

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('store')->once()->andThrow(new RuntimeException('Disk full'));

    expect(fn () => (new StoreAndDispatch)->handle($mockParsedMail, new Envelope))
        ->toThrow(RuntimeException::class, 'Disk full');

    expect(Email::query()->count())->toBe(0)
        ->and(Sender::query()->count())->toBe(0);

    Event::assertNotDispatched(EmailReceived::class);
    Event::assertNotDispatched(MalformedEmailReceived::class);
});

it('deletes the just-written raw file when the row transaction fails', function () {
    Event::fake();

    $storedPath = null;

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('store')->once()->andReturnUsing(function (string $path) use (&$storedPath) {
        $storedPath = $path;
        Storage::disk('local')->put($path, 'raw message');

        return true;
    });

    Schema::drop('emails');

    expect(fn () => (new StoreAndDispatch)->handle($mockParsedMail, new Envelope))
        ->toThrow(QueryException::class);

    expect($storedPath)->not->toBeNull();
    Storage::disk('local')->assertMissing($storedPath);

    Event::assertNotDispatched(EmailReceived::class);
    Event::assertNotDispatched(MalformedEmailReceived::class);
});

it('withdraws the committed row and raw file when enrichment fails transiently', function () {
    // Fake only the announcement events: a bare Event::fake() would also
    // swallow the Eloquent deleted hook that deletes the raw file.
    Event::fake([EmailReceived::class, MalformedEmailReceived::class]);

    $storedPath = null;

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn('<enrichment-outage@example.com>');
    $mockParsedMail->shouldReceive('date')->andReturn(CarbonImmutable::now());
    $mockParsedMail->shouldReceive('sender')->andReturn(new Address('sender@example.com', 'Sender'));
    $mockParsedMail->shouldReceive('store')->once()->andReturnUsing(function (string $path) use (&$storedPath) {
        $storedPath = $path;
        Storage::disk('local')->put($path, 'raw message');

        return true;
    });

    // Break only the enrichment update: the envelope row still commits.
    Schema::table('emails', function (Blueprint $table) {
        $table->dropColumn('parsed_at');
    });

    expect(fn () => (new StoreAndDispatch)->handle($mockParsedMail, new Envelope))
        ->toThrow(QueryException::class);

    // The committed row was never announced: leaving it would strand an
    // orphan no event ever points at, and the tempfail redelivery would
    // store a duplicate beside it. Withdrawing the row and its file lets
    // redelivery re-run ingestion from scratch — exactly one row, exactly
    // one event, once the failure clears.
    expect(Email::query()->count())->toBe(0)
        ->and(Sender::query()->count())->toBe(0)
        ->and($storedPath)->not->toBeNull();

    Storage::disk('local')->assertMissing($storedPath);

    Event::assertNotDispatched(EmailReceived::class);
    Event::assertNotDispatched(MalformedEmailReceived::class);
});

it('keeps mail whose Message-ID exceeds the storable length as Malformed Mail', function () {
    Event::fake();

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn('<'.str_repeat('a', 300).'@example.com>');
    $mockParsedMail->shouldReceive('date')->andReturn(CarbonImmutable::now());
    $mockParsedMail->shouldReceive('sender')->andReturn(new Address('sender@example.com', 'Sender'));
    $mockParsedMail->shouldReceive('store')->once()->andReturnUsing(function (string $path) {
        Storage::disk('local')->put($path, 'raw message');

        return true;
    });

    (new StoreAndDispatch)->handle($mockParsedMail, new Envelope);

    // An oversized value must be classified before the enrichment commit:
    // a QueryException after the envelope row committed would tempfail a
    // deterministic failure into Postfix's redelivery loop.
    $email = Email::query()->sole();

    expect($email->message_id)->toBeNull()
        ->and($email->parsed_at)->toBeNull()
        ->and(Sender::query()->count())->toBe(0);

    Storage::disk('local')->assertExists($email->path());

    Event::assertDispatched(MalformedEmailReceived::class, fn ($event) => $event->email->is($email));
    Event::assertNotDispatched(EmailReceived::class);
});

it('keeps mail whose Date header is outside the storable timestamp range as Malformed Mail', function () {
    Event::fake();

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn('<post-dated@example.com>');
    // Beyond the MySQL TIMESTAMP range; spam commonly post-dates itself.
    $mockParsedMail->shouldReceive('date')->andReturn(CarbonImmutable::parse('2052-01-01 00:00:00', 'UTC'));
    $mockParsedMail->shouldReceive('sender')->andReturn(new Address('sender@example.com', 'Sender'));
    $mockParsedMail->shouldReceive('store')->once()->andReturnUsing(function (string $path) {
        Storage::disk('local')->put($path, 'raw message');

        return true;
    });

    (new StoreAndDispatch)->handle($mockParsedMail, new Envelope);

    $email = Email::query()->sole();

    expect($email->sent_at)->toBeNull()
        ->and($email->parsed_at)->toBeNull();

    Storage::disk('local')->assertExists($email->path());

    Event::assertDispatched(MalformedEmailReceived::class, fn ($event) => $event->email->is($email));
    Event::assertNotDispatched(EmailReceived::class);
});

it('truncates an oversized Header Sender display instead of failing enrichment', function () {
    Event::fake();

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn('<long-display@example.com>');
    $mockParsedMail->shouldReceive('date')->andReturn(CarbonImmutable::now());
    $mockParsedMail->shouldReceive('sender')->andReturn(new Address('sender@example.com', str_repeat('D', 300)));
    $mockParsedMail->shouldReceive('store')->once()->andReturnUsing(function (string $path) {
        Storage::disk('local')->put($path, 'raw message');

        return true;
    });

    (new StoreAndDispatch)->handle($mockParsedMail, new Envelope);

    // Display is presentation, not identity: the mail stays parsed.
    $email = Email::query()->sole();

    expect($email->parsed_at)->not->toBeNull()
        ->and($email->sender?->display)->toBe(str_repeat('D', 255));

    Event::assertDispatched(EmailReceived::class, fn ($event) => $event->email->is($email));
    Event::assertNotDispatched(MalformedEmailReceived::class);
});
