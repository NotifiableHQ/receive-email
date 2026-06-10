<?php

use Illuminate\Support\Facades\Event;
use Notifiable\ReceiveEmail\Enums\RejectionClass;
use Notifiable\ReceiveEmail\Events\SmtpRejectionObserved;

function mailLogFixture(string $name): string
{
    return __DIR__.'/../../Fixtures/mail-log/'.$name;
}

beforeEach(function () {
    $this->logPath = (string) tempnam(sys_get_temp_dir(), 'maillog-');
    $this->offsetDirectory = sys_get_temp_dir().'/maillog-offset-'.uniqid();
    $this->offsetPath = $this->offsetDirectory.'/offset.json';

    config()->set('receive_email.mail-log-path', $this->logPath);
    config()->set('receive_email.mail-log-offset-path', $this->offsetPath);
});

afterEach(function () {
    @unlink($this->logPath);
    @unlink($this->offsetPath);
    @rmdir($this->offsetDirectory);
});

it('parses fixture rejections into SmtpRejectionObserved events', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 6 SMTP-time rejection(s).')
        ->expectsOutputToContain('Skipped 1 unparseable rejection line(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 6);

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::EnvelopeList
            && $event->rejection->envelopeSender === 'spammer@blocked.example'
            && $event->rejection->recipient === 'inbox@receiver.test'
            && $event->rejection->clientHost === 'spam-host.example'
            && $event->rejection->clientIp === '203.0.113.5';
    });

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::Spf
            && $event->rejection->envelopeSender === 'forged@bank.example';
    });

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::Helo
            && $event->rejection->clientIp === '192.0.2.9';
    });

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::RateLimit
            && $event->rejection->clientIp === '203.0.113.77'
            && $event->rejection->envelopeSender === null
            && $event->rejection->recipient === null;
    });

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::Postscreen
            && $event->rejection->clientHost === null
            && $event->rejection->clientIp === '203.0.113.99'
            && $event->rejection->envelopeSender === 'bot@botnet.example';
    });

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::EnvelopeList
            && $event->rejection->envelopeSender === 'crawler@blocked.example';
    });
});

it('persists the offset between runs and never duplicates events', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log')->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 6);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 0 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 6);
});

it('imports only lines appended since the previous run', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log')->assertSuccessful();

    file_put_contents($this->logPath, implode("\n", [
        'Jun  9 13:00:00 mail postfix/smtpd[31001]: connect from mail.example.org[198.51.100.10]',
        'Jun  9 13:00:01 mail postfix/smtpd[31020]: NOQUEUE: reject: RCPT from late.example[203.0.113.50]: 554 5.7.1 <late@blocked.example>: Sender address rejected: Access denied; from=<late@blocked.example> to=<inbox@receiver.test> proto=ESMTP helo=<late.example>',
    ])."\n", FILE_APPEND);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 7);
    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->envelopeSender === 'late@blocked.example';
    });
});

it('restarts from the new file after log rotation', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log')->assertSuccessful();

    // Simulate logrotate: a freshly-created file (new inode) replaces the log.
    $replacement = sys_get_temp_dir().'/maillog-rotated-'.uniqid();
    copy(mailLogFixture('rotated.log'), $replacement);
    rename($replacement, $this->logPath);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 7);
});

it('restarts from the beginning when the log is truncated in place', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log')->assertSuccessful();

    // Same inode, but shorter than the stored offset (copytruncate-style rotation).
    file_put_contents($this->logPath, 'Jun 10 04:00:05 mail postfix/smtpd[40002]: NOQUEUE: reject: RCPT from spam-host.example[203.0.113.5]: 554 5.7.1 <spammer@blocked.example>: Sender address rejected: Access denied; from=<spammer@blocked.example> to=<inbox@receiver.test> proto=ESMTP helo=<spam-host.example>'."\n");

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 7);
});

it('leaves a partially written final line for the next run', function () {
    Event::fake();

    $rejection = 'Jun  9 12:01:10 mail postfix/smtpd[31010]: NOQUEUE: reject: RCPT from spam-host.example[203.0.113.5]: 554 5.7.1 <spammer@blocked.example>: Sender address rejected: Access denied; from=<spammer@blocked.example> to=<inbox@receiver.test> proto=ESMTP helo=<spam-host.example>';

    file_put_contents(
        $this->logPath,
        "Jun  9 12:00:01 mail postfix/smtpd[31001]: connect from mail.example.org[198.51.100.10]\n".substr($rejection, 0, 80)
    );

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 0 SMTP-time rejection(s).')
        ->assertSuccessful();

    file_put_contents($this->logPath, substr($rejection, 80)."\n", FILE_APPEND);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 1);
});

it('fails with guidance when the mail log is missing or unreadable', function () {
    Event::fake();

    unlink($this->logPath);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('missing or not readable')
        ->assertFailed();

    Event::assertNotDispatched(SmtpRejectionObserved::class);
});
