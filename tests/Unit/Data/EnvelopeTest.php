<?php

use Notifiable\ReceiveEmail\Data\Envelope;

it('carries the envelope fields', function () {
    $envelope = new Envelope(
        'sender@example.com',
        ['one@example.com', 'two@example.com'],
        '203.0.113.7',
        '4cVqkW1lq8z2Xw1',
    );

    expect($envelope->sender)->toBe('sender@example.com')
        ->and($envelope->recipients)->toBe(['one@example.com', 'two@example.com'])
        ->and($envelope->clientAddress)->toBe('203.0.113.7')
        ->and($envelope->queueId)->toBe('4cVqkW1lq8z2Xw1');
});

it('defaults to an empty envelope', function () {
    $envelope = new Envelope;

    expect($envelope->sender)->toBeNull()
        ->and($envelope->recipients)->toBe([])
        ->and($envelope->clientAddress)->toBeNull()
        ->and($envelope->queueId)->toBeNull();
});

it('normalizes the empty strings Postfix passes for unavailable macros to null', function () {
    // The pipe's null_sender= attribute passes MAIL FROM:<> as an empty
    // string; it must become null, never survive as a literal address.
    $envelope = new Envelope('', [''], '', '');

    expect($envelope->sender)->toBeNull()
        ->and($envelope->recipients)->toBe([])
        ->and($envelope->clientAddress)->toBeNull()
        ->and($envelope->queueId)->toBeNull();
});
