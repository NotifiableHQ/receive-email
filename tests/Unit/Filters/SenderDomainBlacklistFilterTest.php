<?php

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Filters\SenderDomainBlacklistFilter;

it('returns false when sender domain is in blacklist', function () {
    // Configure blacklist
    Config::set('receive_email.sender-domain-blacklist', ['blocked.com', 'spam.org']);

    // Create a mock address that can return domain
    $address = Address::from(['address' => 'user@blocked.com', 'display' => 'Blocked User']);

    // Fake the ParsedMail with a blacklisted domain
    ParsedMail::fake([
        'sender' => $address,
    ]);

    $filter = new SenderDomainBlacklistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeFalse();
});

it('returns false when sender domain is in blacklist with different case', function () {
    // Configure blacklist
    Config::set('receive_email.sender-domain-blacklist', ['Blocked.COM']);

    // Create a mock address that can return domain
    $address = Address::from(['address' => 'user@blocked.com', 'display' => 'Blocked User']);

    // Fake the ParsedMail with a blacklisted domain (different case)
    ParsedMail::fake([
        'sender' => $address,
    ]);

    $filter = new SenderDomainBlacklistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeFalse();
});

it('returns true when sender domain is not in blacklist', function () {
    // Configure blacklist
    Config::set('receive_email.sender-domain-blacklist', ['blocked.com']);

    // Create a mock address that can return domain
    $address = Address::from(['address' => 'user@example.com', 'display' => 'Allowed User']);

    // Fake the ParsedMail with a non-blacklisted domain
    ParsedMail::fake([
        'sender' => $address,
    ]);

    $filter = new SenderDomainBlacklistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeTrue();
});

it('returns true when blacklist is empty', function () {
    // Configure empty blacklist
    Config::set('receive_email.sender-domain-blacklist', []);

    // Create a mock address that can return domain
    $address = Address::from(['address' => 'user@example.com', 'display' => 'Test User']);

    // Fake the ParsedMail
    ParsedMail::fake([
        'sender' => $address,
    ]);

    $filter = new SenderDomainBlacklistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeTrue();
});
