<?php

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Support\Testing\FakeParsedMail;

it('creates a fake parsed mail instance', function () {
    $fakeParsedMail = new FakeParsedMail;

    expect($fakeParsedMail)
        ->toBeInstanceOf(FakeParsedMail::class)
        ->and($fakeParsedMail)->toBeInstanceOf(\Notifiable\ReceiveEmail\Contracts\ParsedMailContract::class);
});

it('accepts fake data', function () {
    $messageId = '<fake-id@example.com>';
    $date = CarbonImmutable::now();
    $sender = new Address('fake@example.com', 'Fake Sender');

    $fakeParsedMail = (new FakeParsedMail)->fake([
        'id' => $messageId,
        'date' => $date,
        'sender' => $sender,
        'subject' => 'Test Subject',
        'text' => 'Test Content',
        'html' => '<p>Test HTML Content</p>',
    ]);

    expect($fakeParsedMail->id())->toBe($messageId)
        ->and($fakeParsedMail->date())->toBe($date)
        ->and($fakeParsedMail->sender())->toBe($sender)
        ->and($fakeParsedMail->subject())->toBe('Test Subject')
        ->and($fakeParsedMail->text())->toBe('Test Content')
        ->and($fakeParsedMail->html())->toBe('<p>Test HTML Content</p>');
});

it('handles recipients correctly', function () {
    $to = [new Address('to@example.com', 'To User')];
    $cc = [new Address('cc@example.com', 'CC User')];
    $bcc = [new Address('bcc@example.com', 'BCC User')];

    $fakeParsedMail = (new FakeParsedMail)->fake([
        'to' => $to,
        'cc' => $cc,
        'bcc' => $bcc,
    ]);

    expect($fakeParsedMail->to())->toBe($to)
        ->and($fakeParsedMail->cc())->toBe($cc)
        ->and($fakeParsedMail->bcc())->toBe($bcc);

    $recipients = $fakeParsedMail->recipients();
    expect($recipients)
        ->toBeInstanceOf(Recipients::class)
        ->and($recipients->to)->toBe($to)
        ->and($recipients->cc)->toBe($cc)
        ->and($recipients->bcc)->toBe($bcc);
});

it('creates recipients from arrays', function () {
    $fakeParsedMail = (new FakeParsedMail)->fake([
        'to' => [
            ['address' => 'to@example.com', 'display' => 'To User'],
        ],
    ]);

    $to = $fakeParsedMail->to();
    expect($to)
        ->toBeArray()
        ->toHaveCount(1)
        ->and($to[0])->toBeInstanceOf(Address::class)
        ->and($to[0]->address)->toBe('to@example.com');
});

it('generates mail data object', function () {
    // Create required data for Mail object
    $to = [new Address('to@example.com', 'To User')];
    $sender = new Address('sender@example.com', 'Sender');

    $fakeParsedMail = (new FakeParsedMail)->fake([
        'id' => '<test-id@example.com>',
        'date' => CarbonImmutable::now(),
        'sender' => $sender,
        'to' => $to,
        'subject' => 'Test Subject',
    ]);

    $mail = $fakeParsedMail->toMail();

    expect($mail)
        ->toBeInstanceOf(Mail::class)
        ->and($mail->subject)->toBe('Test Subject')
        ->and($mail->sender)->toBe($sender);
});
