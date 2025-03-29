<?php

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Filters\SenderAddressWhitelistFilter;

it('returns true when sender address is in whitelist', function () {
    // Configure whitelist
    Config::set('receive_email.sender-address-whitelist', ['allowed@example.com', 'Another@Test.com']);

    // Fake the ParsedMail with a whitelisted sender
    ParsedMail::fake([
        'sender' => ['address' => 'allowed@example.com', 'display' => 'Allowed Sender'],
    ]);

    $filter = new SenderAddressWhitelistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeTrue();
});

it('returns true when sender address is in whitelist with different case', function () {
    // Configure whitelist
    Config::set('receive_email.sender-address-whitelist', ['Allowed@Example.COM']);

    // Fake the ParsedMail with a whitelisted sender (different case)
    ParsedMail::fake([
        'sender' => ['address' => 'allowed@example.com', 'display' => 'Allowed Sender'],
    ]);

    $filter = new SenderAddressWhitelistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeTrue();
});

it('returns false when sender address is not in whitelist', function () {
    // Configure whitelist
    Config::set('receive_email.sender-address-whitelist', ['allowed@example.com']);

    // Fake the ParsedMail with a non-whitelisted sender
    ParsedMail::fake([
        'sender' => ['address' => 'blocked@example.com', 'display' => 'Blocked Sender'],
    ]);

    $filter = new SenderAddressWhitelistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeFalse();
});

it('returns false when whitelist is empty', function () {
    // Configure empty whitelist
    Config::set('receive_email.sender-address-whitelist', []);

    // Fake the ParsedMail
    ParsedMail::fake([
        'sender' => ['address' => 'test@example.com', 'display' => 'Test Sender'],
    ]);

    $filter = new SenderAddressWhitelistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeFalse();
});
