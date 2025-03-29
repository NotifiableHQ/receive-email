<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Enums\Source;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use Notifiable\ReceiveEmail\ParserParsedMail;
use PhpMimeMailParser\Parser;

beforeEach(function () {
    $this->parser = Mockery::mock(Parser::class);
    $this->parsedMail = new ParserParsedMail($this->parser);
});

it('throws exception when stream source is not a resource', function () {
    $this->parsedMail->source('not-a-resource', Source::Stream);
})->throws(InvalidArgumentException::class, 'Source must be a resource');

it('throws exception when path source is not a string', function () {
    $resource = tmpfile();
    $this->parsedMail->source($resource, Source::Path);
    fclose($resource);
})->throws(InvalidArgumentException::class, 'Source must be a string');

it('throws exception when text source is not a string', function () {
    $resource = tmpfile();
    $this->parsedMail->source($resource, Source::Text);
    fclose($resource);
})->throws(InvalidArgumentException::class, 'Source must be a string');

it('correctly sets stream source', function () {
    $resource = tmpfile();

    $this->parser->shouldReceive('setStream')
        ->once()
        ->with($resource)
        ->andReturnSelf();

    $result = $this->parsedMail->source($resource, Source::Stream);

    expect($result)->toBeInstanceOf(ParserParsedMail::class);

    fclose($resource);
});

it('correctly sets path source', function () {
    $path = '/path/to/email.eml';

    $this->parser->shouldReceive('setPath')
        ->once()
        ->with($path)
        ->andReturnSelf();

    $result = $this->parsedMail->source($path, Source::Path);

    expect($result)->toBeInstanceOf(ParserParsedMail::class);
});

it('correctly sets text source', function () {
    $text = 'email content text';

    $this->parser->shouldReceive('setText')
        ->once()
        ->with($text)
        ->andReturnSelf();

    $result = $this->parsedMail->source($text, Source::Text);

    expect($result)->toBeInstanceOf(ParserParsedMail::class);
});

it('stores the email correctly', function () {
    $path = 'emails/test-email.eml';
    $stream = 'email content';
    $diskName = 'test-disk';

    // Mock the disk
    $disk = Storage::fake($diskName);
    Config::set('receive_email.storage-disk', $diskName);

    $this->parser->shouldReceive('getStream')
        ->once()
        ->andReturn($stream);

    $result = $this->parsedMail->store($path);

    expect($result)->toBeTrue();
    $disk->assertExists($path);
});

it('gets message id correctly', function () {
    $messageId = '<test-message-id@example.com>';

    $this->parser->shouldReceive('getHeader')
        ->once()
        ->with('message-id')
        ->andReturn($messageId);

    $result = $this->parsedMail->id();

    expect($result)->toBe($messageId);

    // Should cache the result
    $result2 = $this->parsedMail->id();
    expect($result2)->toBe($messageId);
});

it('throws exception when message-id header is missing', function () {
    $this->parser->shouldReceive('getHeader')
        ->once()
        ->with('message-id')
        ->andReturn(false);

    $this->parsedMail->id();
})->throws(MalformedMailException::class);

it('gets date correctly', function () {
    $date = 'Wed, 23 Aug 2023 10:21:44 +0000';
    $expectedDate = CarbonImmutable::parse($date)->utc();

    $this->parser->shouldReceive('getHeader')
        ->once()
        ->with('date')
        ->andReturn($date);

    $result = $this->parsedMail->date();

    expect($result)->toBeInstanceOf(CarbonImmutable::class)
        ->and($result->toIso8601String())->toBe($expectedDate->toIso8601String());
});

it('throws exception when date header is missing', function () {
    $this->parser->shouldReceive('getHeader')
        ->once()
        ->with('date')
        ->andReturn(false);

    $this->parsedMail->date();
})->throws(MalformedMailException::class);

it('gets sender from sender header', function () {
    $senderData = [['display' => 'Test Sender', 'address' => 'sender@example.com']];

    $this->parser->shouldReceive('getAddresses')
        ->once()
        ->with('sender')
        ->andReturn($senderData);

    $result = $this->parsedMail->sender();

    expect($result)->toBeInstanceOf(Address::class)
        ->and($result->address)->toBe('sender@example.com')
        ->and($result->display)->toBe('Test Sender');
});

it('gets sender from from header when sender header is empty', function () {
    $fromData = [['display' => 'Test From', 'address' => 'from@example.com']];

    $this->parser->shouldReceive('getAddresses')
        ->once()
        ->with('sender')
        ->andReturn([]);

    $this->parser->shouldReceive('getAddresses')
        ->once()
        ->with('from')
        ->andReturn($fromData);

    $result = $this->parsedMail->sender();

    expect($result)->toBeInstanceOf(Address::class)
        ->and($result->address)->toBe('from@example.com')
        ->and($result->display)->toBe('Test From');
});

it('throws exception when sender and from headers are missing', function () {
    $this->parser->shouldReceive('getAddresses')
        ->once()
        ->with('sender')
        ->andReturn([]);

    $this->parser->shouldReceive('getAddresses')
        ->once()
        ->with('from')
        ->andReturn([]);

    $this->parsedMail->sender();
})->throws(MalformedMailException::class);

it('gets subject correctly', function () {
    $subject = 'Test Subject';

    $this->parser->shouldReceive('getHeader')
        ->once()
        ->with('subject')
        ->andReturn($subject);

    $result = $this->parsedMail->subject();

    expect($result)->toBe($subject);
});

it('gets recipients correctly', function () {
    $toData = [
        ['display' => 'Recipient 1', 'address' => 'recipient1@example.com'],
        ['display' => 'Recipient 2', 'address' => 'recipient2@example.com'],
    ];

    $ccData = [
        ['display' => 'CC Recipient', 'address' => 'cc@example.com'],
    ];

    $bccData = [
        ['display' => 'BCC Recipient', 'address' => 'bcc@example.com'],
    ];

    $this->parser->shouldReceive('getAddresses')
        ->once()
        ->with('to')
        ->andReturn($toData);

    $this->parser->shouldReceive('getAddresses')
        ->once()
        ->with('cc')
        ->andReturn($ccData);

    $this->parser->shouldReceive('getAddresses')
        ->once()
        ->with('bcc')
        ->andReturn($bccData);

    $result = $this->parsedMail->recipients();

    expect($result)->toBeInstanceOf(Recipients::class)
        ->and($result->to)->toHaveCount(2)
        ->and($result->cc)->toHaveCount(1)
        ->and($result->bcc)->toHaveCount(1);
});

it('gets text content correctly', function () {
    $text = 'Plain text content';

    $this->parser->shouldReceive('getMessageBody')
        ->once()
        ->with('text')
        ->andReturn($text);

    $result = $this->parsedMail->text();

    expect($result)->toBe($text);
});

it('returns null when text content is empty', function () {
    $this->parser->shouldReceive('getMessageBody')
        ->once()
        ->with('text')
        ->andReturn('');

    $result = $this->parsedMail->text();

    expect($result)->toBeNull();
});

it('gets html content correctly', function () {
    $html = '<p>HTML content</p>';

    $this->parser->shouldReceive('getMessageBody')
        ->once()
        ->with('html')
        ->andReturn($html);

    $result = $this->parsedMail->html();

    expect($result)->toBe($html);
});

it('returns null when html content is empty', function () {
    $this->parser->shouldReceive('getMessageBody')
        ->once()
        ->with('html')
        ->andReturn('');

    $result = $this->parsedMail->html();

    expect($result)->toBeNull();
});

it('converts to Mail object correctly', function () {
    $messageId = '<test-message-id@example.com>';
    $date = 'Wed, 23 Aug 2023 10:21:44 +0000';
    $subject = 'Test Subject';
    $text = 'Plain text content';
    $html = '<p>HTML content</p>';

    $senderData = [['display' => 'Test Sender', 'address' => 'sender@example.com']];
    $toData = [['display' => 'Recipient', 'address' => 'recipient@example.com']];
    $ccData = [['display' => 'CC Recipient', 'address' => 'cc@example.com']];
    $bccData = [['display' => 'BCC Recipient', 'address' => 'bcc@example.com']];

    $this->parser->shouldReceive('getHeader')
        ->with('message-id')
        ->andReturn($messageId);

    $this->parser->shouldReceive('getHeader')
        ->with('date')
        ->andReturn($date);

    $this->parser->shouldReceive('getHeader')
        ->with('subject')
        ->andReturn($subject);

    $this->parser->shouldReceive('getAddresses')
        ->with('sender')
        ->andReturn($senderData);

    $this->parser->shouldReceive('getAddresses')
        ->with('to')
        ->andReturn($toData);

    $this->parser->shouldReceive('getAddresses')
        ->with('cc')
        ->andReturn($ccData);

    $this->parser->shouldReceive('getAddresses')
        ->with('bcc')
        ->andReturn($bccData);

    $this->parser->shouldReceive('getMessageBody')
        ->with('text')
        ->andReturn($text);

    $this->parser->shouldReceive('getMessageBody')
        ->with('html')
        ->andReturn($html);

    $mail = $this->parsedMail->toMail();

    expect($mail)->toBeInstanceOf(\Notifiable\ReceiveEmail\Data\Mail::class)
        ->and($mail->messageId)->toBe($messageId)
        ->and($mail->subject)->toBe($subject)
        ->and($mail->text)->toBe($text)
        ->and($mail->html)->toBe($html)
        ->and($mail->sender)->toBeInstanceOf(Address::class)
        ->and($mail->sender->address)->toBe('sender@example.com');
});

it('gets header correctly', function () {
    $header = 'header-value';

    $this->parser->shouldReceive('getHeader')
        ->once()
        ->with('test-header')
        ->andReturn($header);

    $result = $this->parsedMail->getHeader('test-header');

    expect($result)->toBe($header);
});

it('returns null when header is empty', function () {
    $this->parser->shouldReceive('getHeader')
        ->once()
        ->with('test-header')
        ->andReturn('');

    $result = $this->parsedMail->getHeader('test-header');

    expect($result)->toBeNull();
});

it('returns null when header is not found', function () {
    $this->parser->shouldReceive('getHeader')
        ->once()
        ->with('test-header')
        ->andReturn(false);

    $result = $this->parsedMail->getHeader('test-header');

    expect($result)->toBeNull();
});
