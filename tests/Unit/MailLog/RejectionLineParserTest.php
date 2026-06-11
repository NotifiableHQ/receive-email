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

it('classifies SPF softfail policy rejections', function () {
    $line = 'Jun  9 12:02:25 mail postfix/smtpd[31011]: NOQUEUE: reject: RCPT from mail.forged.example[198.51.100.7]: 550 5.7.23 <inbox@receiver.test>: Recipient address rejected: Message rejected due to: Receiver policy for SPF Softfail. Please see http://www.openspf.net/Why; from=<forged@bank.example> to=<inbox@receiver.test> proto=ESMTP helo=<mail.forged.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::Spf);
});

it('classifies a list rejection from a sender embedding spf as envelope-list', function () {
    $line = 'Jun  9 12:02:30 mail postfix/smtpd[31011]: NOQUEUE: reject: RCPT from mail.evil.example[203.0.113.66]: 554 5.7.1 <spf-bounces@evil.example>: Sender address rejected: Access denied; from=<spf-bounces@evil.example> to=<inbox@receiver.test> proto=ESMTP helo=<mail.evil.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::EnvelopeList)
        ->and($rejection->envelopeSender)->toBe('spf-bounces@evil.example');
});

it('classifies a list rejection from a sender embedding the policyd-spf reason as envelope-list', function () {
    // A quoted local part can embed arbitrary text, including the exact
    // marker the SPF arm anchors to; the "Access denied" tail must win.
    $line = 'Jun  9 12:02:35 mail postfix/smtpd[31011]: NOQUEUE: reject: RCPT from mail.evil.example[203.0.113.66]: 554 5.7.1 <"message rejected due to: spf fail"@evil.example>: Sender address rejected: Access denied; from=<"message rejected due to: spf fail"@evil.example> to=<inbox@receiver.test> proto=ESMTP helo=<mail.evil.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::EnvelopeList);
});

it('classifies HELO rejections', function () {
    $line = 'Jun  9 12:03:30 mail postfix/smtpd[31012]: NOQUEUE: reject: RCPT from unknown[192.0.2.9]: 504 5.5.2 <localhost>: Helo command rejected: need fully-qualified hostname; from=<someone@somewhere.example> to=<inbox@receiver.test> proto=SMTP helo=<localhost>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::Helo)
        ->and($rejection->clientHost)->toBe('unknown')
        ->and($rejection->clientIp)->toBe('192.0.2.9');
});

it('classifies a helo rejection from a helo embedding spf as helo', function () {
    $line = 'Jun  9 12:03:35 mail postfix/smtpd[31012]: NOQUEUE: reject: RCPT from unknown[192.0.2.10]: 450 4.7.1 <spf.example.com>: Helo command rejected: Host not found; from=<someone@somewhere.example> to=<inbox@receiver.test> proto=SMTP helo=<spf.example.com>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::Helo);
});

it('does not classify non-FQDN sender rejections as envelope-list', function () {
    $line = 'Jun  9 12:03:40 mail postfix/smtpd[31022]: NOQUEUE: reject: RCPT from unknown[203.0.113.110]: 504 5.5.2 <bareuser>: Sender address rejected: need fully-qualified address; from=<bareuser> to=<inbox@receiver.test> proto=SMTP helo=<bare.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection)->not->toBeNull()
        ->and($rejection->rejectionClass)->not->toBe(RejectionClass::EnvelopeList)
        ->and($rejection->rejectionClass)->toBe(RejectionClass::Other)
        ->and($rejection->envelopeSender)->toBe('bareuser');
});

it('does not classify unknown-sender-domain rejections as envelope-list', function () {
    $line = 'Jun  9 12:03:50 mail postfix/smtpd[31023]: NOQUEUE: reject: RCPT from mail.ghost.example[203.0.113.111]: 450 4.1.8 <user@ghost.example>: Sender address rejected: Domain not found; from=<user@ghost.example> to=<inbox@receiver.test> proto=ESMTP helo=<mail.ghost.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection)->not->toBeNull()
        ->and($rejection->rejectionClass)->not->toBe(RejectionClass::EnvelopeList)
        ->and($rejection->rejectionClass)->toBe(RejectionClass::Other)
        ->and($rejection->envelopeSender)->toBe('user@ghost.example');
});

it('classifies in-session rate-limit rejections', function () {
    $line = 'Jun  9 12:04:00 mail postfix/smtpd[31013]: NOQUEUE: reject: RCPT from fast.example[203.0.113.70]: 450 4.7.1 <inbox@receiver.test>: Recipient address rejected: rate limit exceeded; from=<bulk@fast.example> to=<inbox@receiver.test> proto=ESMTP helo=<fast.example>';

    $rejection = $this->parser->parse($line);

    expect($rejection->rejectionClass)->toBe(RejectionClass::RateLimit);
});

it('does not treat connection limit warnings as rejections', function () {
    $lines = [
        'Jun  9 12:04:40 mail postfix/smtpd[31013]: warning: Connection rate limit exceeded: 42 from flood.example[203.0.113.77] for service smtp',
        'Jun  9 12:04:41 mail postfix/smtpd[31013]: warning: Connection concurrency limit exceeded: 11 from flood.example[203.0.113.77] for service smtp',
    ];

    foreach ($lines as $line) {
        expect($this->parser->isRejectionLine($line))->toBeFalse()
            ->and($this->parser->parse($line))->toBeNull();
    }
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

it('parses a postscreen pregreet drop', function () {
    $line = 'Jun  9 12:11:10 mail postfix/postscreen[31021]: PREGREET 11 after 0.08 from [203.0.113.101]:25372: EHLO ylmf-pc\r\n';

    $rejection = $this->parser->parse($line);

    expect($this->parser->isRejectionLine($line))->toBeTrue()
        ->and($rejection)->not->toBeNull()
        ->and($rejection->rejectionClass)->toBe(RejectionClass::Postscreen)
        ->and($rejection->clientHost)->toBeNull()
        ->and($rejection->clientIp)->toBe('203.0.113.101')
        ->and($rejection->envelopeSender)->toBeNull()
        ->and($rejection->recipient)->toBeNull()
        ->and($rejection->rawLine)->toBe($line);
});

it('parses a postscreen hangup drop', function () {
    $line = 'Jun  9 12:11:25 mail postfix/postscreen[31021]: HANGUP after 1.9 from [203.0.113.102]:38492 in tests after SMTP handshake';

    $rejection = $this->parser->parse($line);

    expect($this->parser->isRejectionLine($line))->toBeTrue()
        ->and($rejection)->not->toBeNull()
        ->and($rejection->rejectionClass)->toBe(RejectionClass::Postscreen)
        ->and($rejection->clientIp)->toBe('203.0.113.102')
        ->and($rejection->envelopeSender)->toBeNull();
});

it('parses a postscreen dnsbl rank drop', function () {
    $line = 'Jun  9 12:11:40 mail postfix/postscreen[31021]: DNSBL rank 4 for [203.0.113.103]:42061';

    $rejection = $this->parser->parse($line);

    expect($this->parser->isRejectionLine($line))->toBeTrue()
        ->and($rejection)->not->toBeNull()
        ->and($rejection->rejectionClass)->toBe(RejectionClass::Postscreen)
        ->and($rejection->clientIp)->toBe('203.0.113.103');
});

it('classifies a postscreen connection-count rejection as rate-limit', function () {
    $line = 'Jun  9 12:11:55 mail postfix/postscreen[31021]: NOQUEUE: reject: CONNECT from [203.0.113.104]:51246: too many connections';

    $rejection = $this->parser->parse($line);

    expect($rejection)->not->toBeNull()
        ->and($rejection->rejectionClass)->toBe(RejectionClass::RateLimit)
        ->and($rejection->clientIp)->toBe('203.0.113.104')
        ->and($rejection->envelopeSender)->toBeNull();
});

it('classifies other postscreen connect rejections as postscreen', function () {
    $line = 'Jun  9 12:11:58 mail postfix/postscreen[31021]: NOQUEUE: reject: CONNECT from [203.0.113.105]:41833: all server ports busy';

    $rejection = $this->parser->parse($line);

    expect($rejection)->not->toBeNull()
        ->and($rejection->rejectionClass)->toBe(RejectionClass::Postscreen)
        ->and($rejection->clientIp)->toBe('203.0.113.105');
});

it('does not treat ordinary postscreen lines as rejections', function () {
    $lines = [
        'Jun  9 12:11:00 mail postfix/postscreen[31021]: CONNECT from [203.0.113.101]:25372 to [198.51.100.2]:25',
        'Jun  9 12:11:02 mail postfix/postscreen[31021]: PASS NEW [198.51.100.10]:33672',
        'Jun  9 12:11:03 mail postfix/postscreen[31021]: PASS OLD [198.51.100.10]:33688',
        'Jun  9 12:11:04 mail postfix/postscreen[31021]: DISCONNECT [203.0.113.101]:25372',
        'Jun  9 12:11:05 mail postfix/postscreen[31021]: WHITELISTED [198.51.100.10]:33672',
    ];

    foreach ($lines as $line) {
        expect($this->parser->isRejectionLine($line))->toBeFalse();
    }
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
