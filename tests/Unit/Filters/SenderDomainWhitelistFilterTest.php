<?php

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Filters\SenderDomainWhitelistFilter;

it('returns true when sender domain is in whitelist', function () {
    // Configure whitelist
    Config::set('receive_email.sender-domain-whitelist', ['example.com', 'test.org']);

    // Create an address object
    $address = Address::from(['address' => 'user@example.com', 'display' => 'Test User']);

    // Fake the ParsedMail with a whitelisted domain
    ParsedMail::fake([
        'sender' => $address,
    ]);

    $filter = new SenderDomainWhitelistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeTrue();
});

it('returns true when sender domain is in whitelist with different case', function () {
    // Configure whitelist
    Config::set('receive_email.sender-domain-whitelist', ['Example.COM']);

    // Create an address object
    $address = Address::from(['address' => 'user@example.com', 'display' => 'Test User']);

    // Fake the ParsedMail with a whitelisted domain (different case)
    ParsedMail::fake([
        'sender' => $address,
    ]);

    $filter = new SenderDomainWhitelistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeTrue();
});

it('returns false when sender domain is not in whitelist', function () {
    // Configure whitelist
    Config::set('receive_email.sender-domain-whitelist', ['example.com']);

    // Create an address object
    $address = Address::from(['address' => 'user@blocked-domain.com', 'display' => 'Blocked User']);

    // Fake the ParsedMail with a non-whitelisted domain
    ParsedMail::fake([
        'sender' => $address,
    ]);

    $filter = new SenderDomainWhitelistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeFalse();
});

it('returns false when whitelist is empty', function () {
    // Configure empty whitelist
    Config::set('receive_email.sender-domain-whitelist', []);

    // Create an address object
    $address = Address::from(['address' => 'user@example.com', 'display' => 'Test User']);

    // Fake the ParsedMail
    ParsedMail::fake([
        'sender' => $address,
    ]);

    $filter = new SenderDomainWhitelistFilter;

    expect($filter->filter(ParsedMail::getFacadeRoot()))->toBeFalse();
});
