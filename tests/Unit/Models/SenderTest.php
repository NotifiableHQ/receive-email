<?php

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Notifiable\ReceiveEmail\Models\Sender;

beforeEach(function () {
    Config::set('receive_email.sender-table', 'senders');
    Config::set('receive_email.email-table', 'emails');
    Config::set('receive_email.storage-disk', 'local');

    Storage::fake('local');
});

it('uses the configured table name', function () {
    $tableName = 'custom_senders';
    Config::set('receive_email.sender-table', $tableName);

    $sender = new Sender;

    expect($sender->getTable())->toBe($tableName);
});

it('has many emails', function () {
    $sender = new Sender;

    expect($sender->emails())->toBeInstanceOf(HasMany::class);
});

it('deletes email files when sender is deleted', function () {
    $sender = Sender::create([
        'address' => 'sender@example.com',
        'display' => 'Sender Name',
    ]);

    $email1 = $sender->emails()->create([
        'message_id' => '<test-1@example.com>',
        'sent_at' => now(),
    ]);

    $email2 = $sender->emails()->create([
        'message_id' => '<test-2@example.com>',
        'sent_at' => now(),
    ]);

    Storage::disk('local')->put($email1->path(), 'content 1');
    Storage::disk('local')->put($email2->path(), 'content 2');

    expect(Storage::disk('local')->exists($email1->path()))->toBeTrue()
        ->and(Storage::disk('local')->exists($email2->path()))->toBeTrue();

    $sender->delete();

    expect(Storage::disk('local')->exists($email1->path()))->toBeFalse()
        ->and(Storage::disk('local')->exists($email2->path()))->toBeFalse();
});
