<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Models\Sender;
use Notifiable\ReceiveEmail\StoreAndDispatch;

beforeEach(function () {
    Storage::fake('local');
});

it('stores incoming email and dispatches event', function () {
    // Setup
    Event::fake();

    $messageId = '<test-id@example.com>';
    $date = CarbonImmutable::now();
    $senderAddress = 'sender@example.com';
    $senderDisplay = 'Sender Name';

    // Create a real sender address instead of a mock due to readonly class constraints
    $sender = new Address($senderAddress, $senderDisplay);

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn($messageId);
    $mockParsedMail->shouldReceive('date')->andReturn($date);
    $mockParsedMail->shouldReceive('sender')->andReturn($sender);
    $mockParsedMail->shouldReceive('store')->andReturn(true);

    // Execute
    $storeAndDispatch = new StoreAndDispatch;
    $storeAndDispatch->handle($mockParsedMail);

    // Assert
    $this->assertDatabaseHas('senders', [
        'address' => $senderAddress,
        'display' => $senderDisplay,
    ]);

    $sender = Sender::where('address', $senderAddress)->first();
    $email = $sender->emails()->where('message_id', $messageId)->first();

    expect($email)->not->toBeNull()
        ->and($email->message_id)->toBe($messageId)
        ->and($email->sent_at->toDateTimeString())->toBe($date->toDateTimeString());

    Event::assertDispatched(EmailReceived::class, function ($event) use ($email) {
        return $event->email->is($email);
    });
});

it('stores the file before committing the database transaction', function () {
    Event::fake();

    $messageId = '<order-test@example.com>';
    $date = CarbonImmutable::now();
    $sender = new Address('sender@example.com', 'Sender');

    $storeCalledBeforeCommit = false;

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn($messageId);
    $mockParsedMail->shouldReceive('date')->andReturn($date);
    $mockParsedMail->shouldReceive('sender')->andReturn($sender);
    $mockParsedMail->shouldReceive('store')->once()->andReturnUsing(function () use (&$storeCalledBeforeCommit) {
        // If we can query the email but it's not yet committed, store was called inside the transaction
        $storeCalledBeforeCommit = DB::transactionLevel() > 0;

        return true;
    });

    $storeAndDispatch = new StoreAndDispatch;
    $storeAndDispatch->handle($mockParsedMail);

    expect($storeCalledBeforeCommit)->toBeTrue();
});

it('rolls back transaction on error', function () {
    DB::shouldReceive('beginTransaction')->once();
    DB::shouldReceive('commit')->never();
    DB::shouldReceive('rollBack')->once();

    // Create a real sender address
    $sender = new Address('test@example.com', 'Test Sender');

    $mockParsedMail = mock(ParsedMailContract::class);
    // Make id() throw to trigger rollback during email creation
    $mockParsedMail->shouldReceive('sender')->andReturn($sender);
    $mockParsedMail->shouldReceive('id')->andThrow(new Exception('Test exception'));
    $mockParsedMail->shouldReceive('date')->andReturn(CarbonImmutable::now());

    $storeAndDispatch = new StoreAndDispatch;

    expect(fn () => $storeAndDispatch->handle($mockParsedMail))
        ->toThrow(Exception::class, 'Test exception');
});

it('rolls back database when store fails', function () {
    Event::fake();

    $messageId = '<store-fail@example.com>';
    $date = CarbonImmutable::now();
    $sender = new Address('sender@example.com', 'Sender');

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn($messageId);
    $mockParsedMail->shouldReceive('date')->andReturn($date);
    $mockParsedMail->shouldReceive('sender')->andReturn($sender);
    $mockParsedMail->shouldReceive('store')->once()->andThrow(new RuntimeException('Disk full'));

    $storeAndDispatch = new StoreAndDispatch;

    expect(fn () => $storeAndDispatch->handle($mockParsedMail))
        ->toThrow(RuntimeException::class, 'Disk full');

    $this->assertDatabaseMissing('senders', ['address' => 'sender@example.com']);
    $this->assertDatabaseMissing('emails', ['message_id' => $messageId]);

    Event::assertNotDispatched(EmailReceived::class);
});
