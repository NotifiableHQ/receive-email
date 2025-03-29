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

it('rolls back transaction on error', function () {
    DB::shouldReceive('beginTransaction')->once();
    DB::shouldReceive('commit')->never();
    DB::shouldReceive('rollBack')->once();

    // Create a real sender address
    $sender = new Address('test@example.com', 'Test Sender');

    $mockParsedMail = mock(ParsedMailContract::class);
    $mockParsedMail->shouldReceive('id')->andReturn('<test-id@example.com>');
    $mockParsedMail->shouldReceive('date')->andReturn(CarbonImmutable::now());
    $mockParsedMail->shouldReceive('sender')->andReturn($sender);
    // Make store throw an exception to trigger rollback
    $mockParsedMail->shouldReceive('store')->andThrow(new \Exception('Test exception'));

    $storeAndDispatch = new StoreAndDispatch;

    expect(fn () => $storeAndDispatch->handle($mockParsedMail))
        ->toThrow(\Exception::class, 'Test exception');
});
