<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Data\Envelope;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Exceptions\FailedToDeleteException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Models\Email;
use Notifiable\ReceiveEmail\Models\Sender;

beforeEach(function () {
    Config::set('receive_email.storage-disk', 'local');
    Config::set('receive_email.email-table', 'emails');
    Config::set('receive_email.sender-table', 'senders');

    Storage::fake('local');
});

it('uses the configured table name', function () {
    $tableName = 'custom_emails';
    Config::set('receive_email.email-table', $tableName);

    $email = new Email;

    expect($email->getTable())->toBe($tableName);
});

it('belongs to a sender', function () {
    $email = new Email;

    expect($email->sender())->toBeInstanceOf(BelongsTo::class);
});

it('round-trips envelope fields and parsed_at', function () {
    $email = Email::create([
        'envelope_sender' => 'sender@example.com',
        'envelope_recipients' => ['first@example.com', 'second@example.com'],
        'client_address' => '203.0.113.7',
        'queue_id' => '4cV5xK1r2bz3',
        'parsed_at' => now(),
    ]);

    $email->refresh();

    expect($email->envelope_sender)->toBe('sender@example.com')
        ->and($email->envelope_recipients)->toBe(['first@example.com', 'second@example.com'])
        ->and($email->client_address)->toBe('203.0.113.7')
        ->and($email->queue_id)->toBe('4cV5xK1r2bz3')
        ->and($email->parsed_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('stores Malformed Mail without header enrichment', function () {
    $email = Email::create([
        'envelope_recipients' => ['recipient@example.com'],
    ]);

    $email->refresh();

    expect($email->message_id)->toBeNull()
        ->and($email->sent_at)->toBeNull()
        ->and($email->parsed_at)->toBeNull()
        ->and($email->sender)->toBeNull();
});

it('stores two emails bearing the same Message-ID', function () {
    $messageId = '<duplicate-id@example.com>';

    Email::create(['message_id' => $messageId]);
    Email::create(['message_id' => $messageId]);

    expect(Email::where('message_id', $messageId)->count())->toBe(2);
});

it('throws exception when generating a path without a pre-generated identity', function () {
    $email = new Email;

    $email->path();
})->throws(RuntimeException::class, 'Cannot generate a path before the Email has its ULID and created_at.');

it('derives its storage path from the envelope before the row exists', function () {
    $email = Email::fromEnvelope(new Envelope(
        'envelope-sender@example.com',
        ['envelope-recipient@example.com'],
        '203.0.113.7',
        '4cVqkW1lq8z2Xw1',
    ));

    $path = $email->path();

    expect($email->exists)->toBeFalse()
        ->and($path)->toBe("emails/{$email->created_at->format('Ymd')}/{$email->ulid}");

    $email->save();
    $email->refresh();

    expect($email->path())->toBe($path)
        ->and($email->envelope_sender)->toBe('envelope-sender@example.com')
        ->and($email->envelope_recipients)->toBe(['envelope-recipient@example.com'])
        ->and($email->client_address)->toBe('203.0.113.7')
        ->and($email->queue_id)->toBe('4cVqkW1lq8z2Xw1')
        ->and($email->parsed_at)->toBeNull();
});

it('generates correct path for email storage', function () {
    $sender = Sender::create([
        'address' => 'sender@example.com',
        'display' => 'Sender Name',
    ]);

    $email = $sender->emails()->create([
        'message_id' => '<test-id@example.com>',
        'sent_at' => now(),
    ]);

    $expectedPath = "emails/{$email->created_at->format('Ymd')}/{$email->ulid}";

    expect($email->path())->toEndWith($expectedPath);
});

it('deletes email file when email is deleted', function () {
    $sender = Sender::create([
        'address' => 'sender@example.com',
        'display' => 'Sender Name',
    ]);

    $email = $sender->emails()->create([
        'message_id' => '<test-id@example.com>',
        'sent_at' => now(),
    ]);

    $path = $email->path();
    Storage::disk('local')->put($path, 'test content');

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $email->delete();

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('throws exception when file cannot be deleted', function () {
    $filesystemMock = Mockery::mock(Filesystem::class);
    $filesystemMock->shouldReceive('delete')->andReturn(false);
    $filesystemMock->shouldReceive('path')->andReturn('');

    Storage::shouldReceive('disk')
        ->andReturn($filesystemMock);

    $sender = Sender::create([
        'address' => 'sender@example.com',
        'display' => 'Sender Name',
    ]);

    $email = $sender->emails()->create([
        'message_id' => '<test-id@example.com>',
        'sent_at' => now(),
    ]);

    $email->delete();
})->throws(FailedToDeleteException::class);

it('can get ParsedMail from email file', function () {
    ParsedMail::shouldReceive('source')
        ->once()
        ->andReturn(Mockery::mock(ParsedMailContract::class));

    $sender = Sender::create([
        'address' => 'sender@example.com',
        'display' => 'Sender Name',
    ]);

    $email = $sender->emails()->create([
        'message_id' => '<test-id@example.com>',
        'sent_at' => now(),
    ]);

    expect($email->parsedMail())->toBeInstanceOf(ParsedMailContract::class);
});

it('can check if email was sent to specific address', function () {
    // Set up fake ParsedMail with recipients
    ParsedMail::fake([
        'to' => [
            ['address' => 'recipient1@example.com', 'display' => 'Recipient 1'],
            ['address' => 'recipient2@example.com', 'display' => 'Recipient 2'],
        ],
        'cc' => [
            ['address' => 'cc@example.com', 'display' => 'CC Recipient'],
        ],
    ]);

    $sender = Sender::create([
        'address' => 'sender@example.com',
        'display' => 'Sender Name',
    ]);

    $email = $sender->emails()->create([
        'message_id' => '<test-id@example.com>',
        'sent_at' => now(),
    ]);

    // Test case sensitivity
    expect($email->wasSentTo('recipient1@example.com'))->toBeTrue()
        ->and($email->wasSentTo('RECIPIENT1@EXAMPLE.COM'))->toBeTrue()
        ->and($email->wasSentTo('cc@example.com'))->toBeTrue()
        ->and($email->wasSentTo('unknown@example.com'))->toBeFalse();

    // Test with only TO recipients
    expect($email->mailboxes(false))->not->toContain('cc@example.com');
});
