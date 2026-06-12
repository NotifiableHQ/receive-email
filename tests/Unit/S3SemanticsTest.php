<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Notifiable\ReceiveEmail\Data\Envelope;
use Notifiable\ReceiveEmail\Enums\Source;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Exceptions\FailedToReadException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Models\Email;
use Notifiable\ReceiveEmail\StoreAndDispatch;

use function Notifiable\ReceiveEmail\storage;

/*
 * Real-S3-semantics suite: proves store → parsedMail() read → delete against
 * an actual S3 API (league/flysystem-aws-s3-v3), not an adapter fake. CI runs
 * it against a MinIO container (see .github/workflows/ci.yml); locally it
 * skips unless RECEIVE_EMAIL_S3_ENDPOINT points at an S3-compatible endpoint
 * with a bucket already created.
 */

uses()->group('s3');

$missingS3Endpoint = getenv('RECEIVE_EMAIL_S3_ENDPOINT') === false;

beforeEach(function () {
    Config::set('filesystems.disks.s3-semantics', [
        'driver' => 's3',
        'key' => env('RECEIVE_EMAIL_S3_KEY', 'minioadmin'),
        'secret' => env('RECEIVE_EMAIL_S3_SECRET', 'minioadmin'),
        'region' => env('RECEIVE_EMAIL_S3_REGION', 'us-east-1'),
        'bucket' => env('RECEIVE_EMAIL_S3_BUCKET', 'receive-email'),
        'endpoint' => env('RECEIVE_EMAIL_S3_ENDPOINT'),
        'use_path_style_endpoint' => true,
        'throw' => false,
    ]);

    Config::set('receive_email.storage-disk', 's3-semantics');
});

it('stores, re-reads, and deletes the raw message end-to-end on S3', function () {
    Event::fake();

    $raw = "Message-ID: <s3-semantics@example.com>\r\n"
        ."Date: Wed, 23 Aug 2023 10:21:44 +0000\r\n"
        ."From: Sender Name <sender@example.com>\r\n"
        ."To: recipient@example.com\r\n"
        ."Subject: S3 semantics\r\n"
        ."\r\n"
        .'Body over S3';

    (new StoreAndDispatch)->handle(ParsedMail::source($raw, Source::Text), new Envelope(
        'envelope-sender@example.com',
        ['envelope-recipient@example.com'],
        '203.0.113.7',
        '4cVqkW1lq8z2Xw1',
    ));

    $email = Email::query()->sole();

    expect(storage()->exists($email->path()))->toBeTrue()
        ->and($email->parsed_at)->not->toBeNull();

    // The read path re-downloads the raw message from the remote disk.
    $parsedMail = $email->parsedMail();

    expect($parsedMail->id())->toBe('<s3-semantics@example.com>')
        ->and($parsedMail->subject())->toBe('S3 semantics')
        ->and($parsedMail->text())->toBe('Body over S3');

    Event::assertDispatched(EmailReceived::class, fn ($event) => $event->email->is($email));

    $path = $email->path();
    $email->delete();

    expect(storage()->exists($path))->toBeFalse();
})->skip(
    $missingS3Endpoint || ! extension_loaded('mailparse'),
    'Set RECEIVE_EMAIL_S3_ENDPOINT (and install mailparse) to run the S3 semantics suite.'
);

it('throws FailedToReadException for a missing object on S3', function () {
    $email = Email::fromEnvelope(new Envelope(
        'envelope-sender@example.com',
        ['envelope-recipient@example.com'],
    ));
    $email->save();

    expect(fn () => $email->parsedMail())
        ->toThrow(FailedToReadException::class, $email->path());
})->skip($missingS3Endpoint, 'Set RECEIVE_EMAIL_S3_ENDPOINT to run the S3 semantics suite.');
