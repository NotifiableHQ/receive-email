<?php

use Notifiable\ReceiveEmail\Data\Address;

it('creates an address object with valid email', function () {
    $address = new Address('test@example.com', 'Test User');

    expect($address)
        ->toBeInstanceOf(Address::class)
        ->and($address->address)->toBe('test@example.com')
        ->and($address->display)->toBe('Test User');
});

it('throws exception when email is invalid', function () {
    new Address('invalid-email', 'Invalid User');
})->throws(InvalidArgumentException::class);

it('creates address from array data', function () {
    $address = Address::from([
        'address' => 'user@example.com',
        'display' => 'Example User',
    ]);

    expect($address)
        ->toBeInstanceOf(Address::class)
        ->and($address->address)->toBe('user@example.com')
        ->and($address->display)->toBe('Example User');
});

it('creates multiple addresses from array data', function () {
    $addresses = Address::fromMany([
        ['address' => 'user1@example.com', 'display' => 'User One'],
        ['address' => 'user2@example.com', 'display' => 'User Two'],
    ]);

    expect($addresses)
        ->toBeArray()
        ->toHaveCount(2)
        ->and($addresses[0])->toBeInstanceOf(Address::class)
        ->and($addresses[0]->address)->toBe('user1@example.com')
        ->and($addresses[1])->toBeInstanceOf(Address::class)
        ->and($addresses[1]->address)->toBe('user2@example.com');
});

it('extracts domain from email address', function () {
    $address = new Address('user@example.com', 'Example User');

    expect($address->domain())->toBe('example.com');
});
