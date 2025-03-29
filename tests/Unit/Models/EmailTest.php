<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
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

    expect($email->sender())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
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
    $filesystemMock = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
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
        ->andReturn(\Mockery::mock(ParsedMailContract::class));

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
