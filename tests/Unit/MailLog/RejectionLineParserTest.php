<?php

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Enums\RejectionClass;
use Notifiable\ReceiveEmail\MailLog\RejectionLineParser;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 00:00:00'));

    $this->parser = new RejectionLineParser;
});

it('parses an envelope-list rejection from a traditional syslog line', function () {
    $line = 'Jun  9 12:01:10 mail postfix/smtpd[31010]: NOQUEUE: reject: RCPT from spam-host.example[203.0.113.5]: 554 5.7.1 <spammer@blocked.example>: Sender address rejected: Access denied; from=<spammer@blocked.example> to=<inbox@receiver.test> proto=ESMTP helo=<spam-host.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection)->not->toBeNull()
        ->and($rejection->timestamp->toDateTimeString())->toBe('2026-06-09 12:01:10')
        ->and($rejection->clientHost)->toBe('spam-host.example')
        ->and($rejection->clientIp)->toBe('203.0.113.5')
        ->and($rejection->envelopeSender)->toBe('spammer@blocked.example')
        ->and($rejection->recipient)->toBe('inbox@receiver.test')
        ->and($rejection->rejectionClass)->toBe(RejectionClass::EnvelopeList)
        ->and($rejection->rawLine)->toBe($line);
});

it('parses an ISO 8601 timestamped rejection', function () {
    $line = '2026-06-09T12:07:30.123456+00:00 mail postfix/smtpd[31016]: NOQUEUE: reject: RCPT from unknown[198.51.100.33]: 554 5.7.1 <crawler@blocked.example>: Sender address rejected: Access denied; from=<crawler@blocked.example> to=<inbox@receiver.test> proto=ESMTP helo=<crawler.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection)->not->toBeNull()
        ->and($rejection->timestamp->toIso8601String())->toBe('2026-06-09T12:07:30+00:00')
        ->and($rejection->clientHost)->toBe('unknown')
        ->and($rejection->rejectionClass)->toBe(RejectionClass::EnvelopeList);
});

it('infers last year for syslog timestamps that would be in the future', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 00:00:00'));

    $line = 'Dec 31 23:59:59 mail postfix/smtpd[31010]: NOQUEUE: reject: RCPT from spam-host.example[203.0.113.5]: 554 5.7.1 <spammer@blocked.example>: Sender address rejected: Access denied; from=<spammer@blocked.example> to=<inbox@receiver.test> proto=ESMTP helo=<spam-host.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection->timestamp->toDateTimeString())->toBe('2025-12-31 23:59:59');
});

it('classifies SPF rejections', function () {
    $line = 'Jun  9 12:02:20 mail postfix/smtpd[31011]: NOQUEUE: reject: RCPT from mail.forged.example[198.51.100.7]: 550 5.7.23 <inbox@receiver.test>: Recipient address rejected: Message rejected due to: SPF fail - not authorized; from=<forged@bank.example> to=<inbox@receiver.test> proto=ESMTP helo=<mail.forged.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::Spf)
        ->and($rejection->envelopeSender)->toBe('forged@bank.example');
});

it('classifies HELO rejections', function () {
    $line = 'Jun  9 12:03:30 mail postfix/smtpd[31012]: NOQUEUE: reject: RCPT from unknown[192.0.2.9]: 504 5.5.2 <localhost>: Helo command rejected: need fully-qualified hostname; from=<someone@somewhere.example> to=<inbox@receiver.test> proto=SMTP helo=<localhost>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::Helo)
        ->and($rejection->clientHost)->toBe('unknown')
        ->and($rejection->clientIp)->toBe('192.0.2.9');
});

it('classifies in-session rate-limit rejections', function () {
    $line = 'Jun  9 12:04:00 mail postfix/smtpd[31013]: NOQUEUE: reject: RCPT from fast.example[203.0.113.70]: 450 4.7.1 <inbox@receiver.test>: Recipient address rejected: rate limit exceeded; from=<bulk@fast.example> to=<inbox@receiver.test> proto=ESMTP helo=<fast.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::RateLimit);
});

it('parses connection rate limit warnings without envelope data', function () {
    $line = 'Jun  9 12:04:40 mail postfix/smtpd[31013]: warning: Connection rate limit exceeded: 42 from flood.example[203.0.113.77] for service smtp';

    $rejection = $this->parser->parse($line);

    expect($rejection)->not->toBeNull()
        ->and($rejection->rejectionClass)->toBe(RejectionClass::RateLimit)
        ->and($rejection->clientHost)->toBe('flood.example')
        ->and($rejection->clientIp)->toBe('203.0.113.77')
        ->and($rejection->envelopeSender)->toBeNull()
        ->and($rejection->recipient)->toBeNull();
});

it('parses postscreen rejections', function () {
    $line = 'Jun  9 12:05:50 mail postfix/postscreen[31014]: NOQUEUE: reject: RCPT from [203.0.113.99]:54321: 550 5.7.1 Service unavailable; client [203.0.113.99] blocked using zen.spamhaus.org; from=<bot@botnet.example>, to=<inbox@receiver.test>, proto=ESMTP, helo=<bot.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection)->not->toBeNull()
        ->and($rejection->rejectionClass)->toBe(RejectionClass::Postscreen)
        ->and($rejection->clientHost)->toBeNull()
        ->and($rejection->clientIp)->toBe('203.0.113.99')
        ->and($rejection->envelopeSender)->toBe('bot@botnet.example')
        ->and($rejection->recipient)->toBe('inbox@receiver.test');
});

it('classifies unrecognized reject reasons as other', function () {
    $line = 'Jun  9 12:08:00 mail postfix/smtpd[31017]: NOQUEUE: reject: RCPT from open.example[203.0.113.80]: 554 5.7.1 <other@elsewhere.test>: Relay access denied; from=<sender@open.example> to=<other@elsewhere.test> proto=ESMTP helo=<open.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::Other);
});

it('leaves the recipient null when the rejection happens before RCPT', function () {
    $line = 'Jun  9 12:09:00 mail postfix/smtpd[31018]: NOQUEUE: reject: MAIL from bad.example[203.0.113.60]: 552 5.3.4 Message size exceeds fixed limit; from=<big@bad.example> proto=ESMTP helo=<bad.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection)->not->toBeNull()
        ->and($rejection->envelopeSender)->toBe('big@bad.example')
        ->and($rejection->recipient)->toBeNull();
});

it('keeps the null sender as an empty envelope sender', function () {
    $line = 'Jun  9 12:10:00 mail postfix/smtpd[31019]: NOQUEUE: reject: RCPT from bounce.example[203.0.113.61]: 554 5.7.1 <>: Sender address rejected: Access denied; from=<> to=<inbox@receiver.test> proto=ESMTP helo=<bounce.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection->envelopeSender)->toBe('')
        ->and($rejection->rejectionClass)->toBe(RejectionClass::EnvelopeList);
});

it('returns null for a malformed rejection line', function () {
    $line = 'Jun  9 12:06:55 mail postfix/smtpd[31015]: NOQUEUE: reject: malformed beyond recognition';

    expect($this->parser->isRejectionLine($line))->toBeTrue()
        ->and($this->parser->parse($line))->toBeNull();
});

it('does not treat ordinary log lines as rejections', function () {
    $lines = [
        'Jun  9 12:00:01 mail postfix/smtpd[31001]: connect from mail.example.org[198.51.100.10]',
        'Jun  9 12:00:02 mail postfix/cleanup[31002]: 4F8C2A0012: message-id=<ok-1@example.org>',
        'Jun  9 12:00:03 mail postfix/smtpd[31001]: disconnect from mail.example.org[198.51.100.10]',
    ];

    foreach ($lines as $line) {
        expect($this->parser->isRejectionLine($line))->toBeFalse();
    }
});
