<?php

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;

it('creates a mail object with required fields', function () {
    $messageId = '<test-id@example.com>';
    $date = CarbonImmutable::now();
    $sender = new Address('sender@example.com', 'Sender');
    $to = [new Address('recipient@example.com', 'Recipient')];
    $recipients = new Recipients($to, [], []);

    $mail = new Mail($messageId, $date, $sender, $recipients);

    expect($mail)
        ->toBeInstanceOf(Mail::class)
        ->and($mail->messageId)->toBe($messageId)
        ->and($mail->date)->toBe($date)
        ->and($mail->sender)->toBe($sender)
        ->and($mail->recipients)->toBe($recipients)
        ->and($mail->subject)->toBeNull()
        ->and($mail->text)->toBeNull()
        ->and($mail->html)->toBeNull();
});

it('creates a mail object with all fields', function () {
    $messageId = '<test-id@example.com>';
    $date = CarbonImmutable::now();
    $sender = new Address('sender@example.com', 'Sender');
    $to = [new Address('recipient@example.com', 'Recipient')];
    $recipients = new Recipients($to, [], []);
    $subject = 'Test Subject';
    $text = 'Test Body';
    $html = '<p>Test HTML Body</p>';

    $mail = new Mail($messageId, $date, $sender, $recipients, $subject, $text, $html);

    expect($mail)
        ->toBeInstanceOf(Mail::class)
        ->and($mail->messageId)->toBe($messageId)
        ->and($mail->date)->toBe($date)
        ->and($mail->sender)->toBe($sender)
        ->and($mail->recipients)->toBe($recipients)
        ->and($mail->subject)->toBe($subject)
        ->and($mail->text)->toBe($text)
        ->and($mail->html)->toBe($html);
});
