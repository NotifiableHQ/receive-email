<?php

use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;

it('creates a recipients object with to recipients', function () {
    $to = [new Address('to@example.com', 'To User')];
    $recipients = new Recipients($to, [], []);

    expect($recipients)
        ->toBeInstanceOf(Recipients::class)
        ->and($recipients->to)->toBe($to)
        ->and($recipients->cc)->toBeEmpty()
        ->and($recipients->bcc)->toBeEmpty();
});

it('creates a recipients object with cc recipients', function () {
    $cc = [new Address('cc@example.com', 'CC User')];
    $recipients = new Recipients([], $cc, []);

    expect($recipients)
        ->toBeInstanceOf(Recipients::class)
        ->and($recipients->to)->toBeEmpty()
        ->and($recipients->cc)->toBe($cc)
        ->and($recipients->bcc)->toBeEmpty();
});

it('creates a recipients object with bcc recipients', function () {
    $bcc = [new Address('bcc@example.com', 'BCC User')];
    $recipients = new Recipients([], [], $bcc);

    expect($recipients)
        ->toBeInstanceOf(Recipients::class)
        ->and($recipients->to)->toBeEmpty()
        ->and($recipients->cc)->toBeEmpty()
        ->and($recipients->bcc)->toBe($bcc);
});

it('throws exception when no recipients are provided', function () {
    new Recipients([], [], []);
})->throws(MalformedMailException::class);

it('extracts to email addresses', function () {
    $to = [
        new Address('to1@example.com', 'To User 1'),
        new Address('to2@example.com', 'To User 2'),
    ];
    $recipients = new Recipients($to, [], []);

    expect($recipients->toAddresses())
        ->toBeArray()
        ->toHaveCount(2)
        ->toContain('to1@example.com', 'to2@example.com');
});

it('extracts cc email addresses', function () {
    $cc = [
        new Address('cc1@example.com', 'CC User 1'),
        new Address('cc2@example.com', 'CC User 2'),
    ];
    $recipients = new Recipients([], $cc, []);

    expect($recipients->ccAddresses())
        ->toBeArray()
        ->toHaveCount(2)
        ->toContain('cc1@example.com', 'cc2@example.com');
});

it('extracts bcc email addresses', function () {
    $bcc = [
        new Address('bcc1@example.com', 'BCC User 1'),
        new Address('bcc2@example.com', 'BCC User 2'),
    ];
    $recipients = new Recipients([], [], $bcc);

    expect($recipients->bccAddresses())
        ->toBeArray()
        ->toHaveCount(2)
        ->toContain('bcc1@example.com', 'bcc2@example.com');
});

it('extracts all email addresses', function () {
    $to = [new Address('to@example.com', 'To User')];
    $cc = [new Address('cc@example.com', 'CC User')];
    $bcc = [new Address('bcc@example.com', 'BCC User')];

    $recipients = new Recipients($to, $cc, $bcc);

    expect($recipients->allAddresses())
        ->toBeArray()
        ->toHaveCount(3)
        ->toContain('to@example.com', 'cc@example.com', 'bcc@example.com');
});
