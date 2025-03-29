<?php

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Filters\SenderAddressBlacklistFilter;

it('returns false when sender address is in blacklist', function () {
    // Configure blacklist
    Config::set('receive_email.sender-address-blacklist', ['blocked@example.com', 'Another@Block.com']);

    // Fake the ParsedMail with a blacklisted sender
    ParsedMail::fake([
        'sender' => ['address' => 'blocked@example.com', 'display' => 'Blocked Sender'],
    ]);

    $filter = new SenderAddressBlacklistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeFalse();
});

it('returns false when sender address is in blacklist with different case', function () {
    // Configure blacklist
    Config::set('receive_email.sender-address-blacklist', ['Blocked@Example.COM']);

    // Fake the ParsedMail with a blacklisted sender (different case)
    ParsedMail::fake([
        'sender' => ['address' => 'blocked@example.com', 'display' => 'Blocked Sender'],
    ]);

    $filter = new SenderAddressBlacklistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeFalse();
});

it('returns true when sender address is not in blacklist', function () {
    // Configure blacklist
    Config::set('receive_email.sender-address-blacklist', ['blocked@example.com']);

    // Fake the ParsedMail with a non-blacklisted sender
    ParsedMail::fake([
        'sender' => ['address' => 'allowed@example.com', 'display' => 'Allowed Sender'],
    ]);

    $filter = new SenderAddressBlacklistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeTrue();
});

it('returns true when blacklist is empty', function () {
    // Configure empty blacklist
    Config::set('receive_email.sender-address-blacklist', []);

    // Fake the ParsedMail
    ParsedMail::fake([
        'sender' => ['address' => 'test@example.com', 'display' => 'Test Sender'],
    ]);

    $filter = new SenderAddressBlacklistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeTrue();
});
