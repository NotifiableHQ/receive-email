<?php

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Models\Sender;

beforeEach(function () {
    Config::set('receive_email.sender-table', 'senders');
});

it('uses the configured table name', function () {
    $tableName = 'custom_senders';
    Config::set('receive_email.sender-table', $tableName);

    $sender = new Sender;

    expect($sender->getTable())->toBe($tableName);
});

it('has many emails', function () {
    $sender = new Sender;

    expect($sender->emails())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});
