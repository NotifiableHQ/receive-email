<?php

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Enums\Source as SourceEnum;
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

it('uses default values when no fake data is provided', function () {
    $fakeParsedMail = new FakeParsedMail;

    expect($fakeParsedMail->id())->toContain('fake-message-id-')
        ->and($fakeParsedMail->date())->toBeInstanceOf(CarbonImmutable::class)
        ->and($fakeParsedMail->sender())->toBeInstanceOf(Address::class)
        ->and($fakeParsedMail->sender()->address)->toBe('fake@example.com')
        ->and($fakeParsedMail->to())->toBeArray()->toBeEmpty()
        ->and($fakeParsedMail->cc())->toBeArray()->toBeEmpty()
        ->and($fakeParsedMail->bcc())->toBeArray()->toBeEmpty()
        ->and($fakeParsedMail->subject())->toBeNull()
        ->and($fakeParsedMail->text())->toBeNull()
        ->and($fakeParsedMail->html())->toBeNull();
});

it('handles source method correctly', function () {
    $fakeParsedMail = new FakeParsedMail;
    $result = $fakeParsedMail->source('some-source', SourceEnum::Stream);

    expect($result)->toBe($fakeParsedMail);
});

it('handles store method correctly', function () {
    $fakeParsedMail = (new FakeParsedMail)->fake(['stored' => true]);
    $result = $fakeParsedMail->store('/path/to/store');

    expect($result)->toBeTrue();

    $fakeParsedMail = new FakeParsedMail;
    $result = $fakeParsedMail->store('/path/to/store');

    expect($result)->toBeFalse();
});

it('parses string date correctly', function () {
    $dateString = '2023-01-01 12:00:00';
    $fakeParsedMail = (new FakeParsedMail)->fake(['date' => $dateString]);

    expect($fakeParsedMail->date())
        ->toBeInstanceOf(CarbonImmutable::class)
        ->and($fakeParsedMail->date()->format('Y-m-d H:i:s'))->toBe('2023-01-01 12:00:00');
});

it('uses from as fallback for sender', function () {
    $from = new Address('from@example.com', 'From User');
    $fakeParsedMail = (new FakeParsedMail)->fake(['from' => $from]);

    expect($fakeParsedMail->sender())->toBe($from);

    // Test with array data
    $fakeParsedMail = (new FakeParsedMail)->fake([
        'from' => ['address' => 'array-from@example.com', 'display' => 'Array From'],
    ]);

    expect($fakeParsedMail->sender())
        ->toBeInstanceOf(Address::class)
        ->and($fakeParsedMail->sender()->address)->toBe('array-from@example.com');
});

it('handles empty recipient arrays correctly', function () {
    $fakeParsedMail = (new FakeParsedMail)->fake([
        'to' => [],
        'cc' => [],
        'bcc' => [],
    ]);

    expect($fakeParsedMail->to())->toBeArray()->toBeEmpty()
        ->and($fakeParsedMail->cc())->toBeArray()->toBeEmpty()
        ->and($fakeParsedMail->bcc())->toBeArray()->toBeEmpty();
});

it('handles missing recipient arrays correctly', function () {
    $fakeParsedMail = new FakeParsedMail;

    expect($fakeParsedMail->to())->toBeArray()->toBeEmpty()
        ->and($fakeParsedMail->cc())->toBeArray()->toBeEmpty()
        ->and($fakeParsedMail->bcc())->toBeArray()->toBeEmpty();
});

it('handles array-formatted cc and bcc', function () {
    $fakeParsedMail = (new FakeParsedMail)->fake([
        'cc' => [['address' => 'cc@example.com', 'display' => 'CC User']],
        'bcc' => [['address' => 'bcc@example.com', 'display' => 'BCC User']],
    ]);

    expect($fakeParsedMail->cc())
        ->toBeArray()
        ->toHaveCount(1)
        ->and($fakeParsedMail->cc()[0])->toBeInstanceOf(Address::class)
        ->and($fakeParsedMail->cc()[0]->address)->toBe('cc@example.com')
        ->and($fakeParsedMail->bcc())
        ->toBeArray()
        ->toHaveCount(1)
        ->and($fakeParsedMail->bcc()[0])->toBeInstanceOf(Address::class)
        ->and($fakeParsedMail->bcc()[0]->address)->toBe('bcc@example.com');
});

it('accepts Recipients object directly', function () {
    $recipients = new Recipients(
        [new Address('to@example.com', 'To User')],
        [new Address('cc@example.com', 'CC User')],
        [new Address('bcc@example.com', 'BCC User')]
    );

    $fakeParsedMail = (new FakeParsedMail)->fake(['recipients' => $recipients]);

    expect($fakeParsedMail->recipients())->toBe($recipients);
});

it('handles header methods correctly', function () {
    $fakeParsedMail = (new FakeParsedMail)->fake([
        'header' => [
            'x-custom-header' => 'Custom Value',
        ],
    ]);

    expect($fakeParsedMail->getHeader('x-custom-header'))->toBe('Custom Value')
        ->and($fakeParsedMail->getHeader('non-existent'))->toBeNull()
        ->and($fakeParsedMail->getHeaderOrFail('x-custom-header'))->toBe('Custom Value')
        ->and($fakeParsedMail->getHeaderOrFail('non-existent'))->toBe('');
});

it('accepts Mail object directly', function () {
    $mail = new Mail(
        '<test-id@example.com>',
        CarbonImmutable::now(),
        new Address('sender@example.com', 'Sender'),
        new Recipients(
            [new Address('to@example.com', 'To User')],
            [],  // Empty cc array
            []   // Empty bcc array
        ),
        'Test Subject',
        'Test Content',
        '<p>Test HTML Content</p>'
    );

    $fakeParsedMail = (new FakeParsedMail)->fake(['mail' => $mail]);

    expect($fakeParsedMail->toMail())->toBe($mail);
});
