<?php

use Illuminate\Console\CacheCommandMutex;
use Illuminate\Support\Facades\Event;
use Notifiable\ReceiveEmail\Console\Commands\ImportMailLogCommand;
use Notifiable\ReceiveEmail\Enums\RejectionClass;
use Notifiable\ReceiveEmail\Events\SmtpRejectionObserved;

function mailLogFixture(string $name): string
{
    return __DIR__.'/../../Fixtures/mail-log/'.$name;
}

function smtpdRejectLine(string $sender, string $time = '13:00:01'): string
{
    return "Jun  9 {$time} mail postfix/smtpd[31020]: NOQUEUE: reject: RCPT from spam-host.example[203.0.113.5]: 554 5.7.1 <{$sender}>: Sender address rejected: Access denied; from=<{$sender}> to=<inbox@receiver.test> proto=ESMTP helo=<spam-host.example>";
}

beforeEach(function () {
    $this->logPath = (string) tempnam(sys_get_temp_dir(), 'maillog-');
    $this->offsetDirectory = sys_get_temp_dir().'/maillog-offset-'.uniqid();
    $this->offsetPath = $this->offsetDirectory.'/offset.json';

    config()->set('receive_email.mail-log-path', $this->logPath);
    config()->set('receive_email.mail-log-offset-path', $this->offsetPath);
});

afterEach(function () {
    @chmod($this->offsetDirectory, 0755);
    @unlink($this->logPath);
    @unlink($this->offsetPath);
    @unlink($this->offsetPath.'.tmp');
    @rmdir($this->offsetDirectory);
    @unlink($this->offsetDirectory);
});

it('parses fixture rejections into SmtpRejectionObserved events', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true])
        ->expectsOutputToContain('Observed 12 SMTP-time rejection(s).')
        ->expectsOutputToContain('Skipped 1 unparseable rejection line(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 12);

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

    // reject_non_fqdn_sender and reject_unknown_sender_domain also log
    // "Sender address rejected" but are not Envelope Sender list decisions.
    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::Other
            && $event->rejection->envelopeSender === 'bareuser';
    });

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::Other
            && $event->rejection->envelopeSender === 'user@ghost.example';
    });

    Event::assertNotDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::EnvelopeList
            && in_array($event->rejection->envelopeSender, ['bareuser', 'user@ghost.example'], true);
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

    // Postscreen enforce-mode drops that never produce a NOQUEUE line.
    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::Postscreen
            && $event->rejection->clientIp === '203.0.113.101'
            && str_contains($event->rejection->rawLine, 'PREGREET');
    });

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::Postscreen
            && $event->rejection->clientIp === '203.0.113.102'
            && str_contains($event->rejection->rawLine, 'HANGUP');
    });

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::Postscreen
            && $event->rejection->clientIp === '203.0.113.103'
            && str_contains($event->rejection->rawLine, 'DNSBL rank');
    });

    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->rejectionClass === RejectionClass::RateLimit
            && $event->rejection->clientIp === '203.0.113.104'
            && str_contains($event->rejection->rawLine, 'too many connections');
    });
});

it('starts at the end of the log on the first run', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('No stored offset; starting at the current end of the mail log.')
        ->assertSuccessful();

    Event::assertNotDispatched(SmtpRejectionObserved::class);

    file_put_contents($this->logPath, smtpdRejectLine('late@blocked.example')."\n", FILE_APPEND);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 1);
    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->envelopeSender === 'late@blocked.example';
    });
});

it('stops the first-run offset before a partially written final line', function () {
    Event::fake();

    $rejection = smtpdRejectLine('partial@blocked.example');

    file_put_contents(
        $this->logPath,
        "Jun  9 12:00:01 mail postfix/smtpd[31001]: connect from mail.example.org[198.51.100.10]\n".substr($rejection, 0, 80)
    );

    $this->artisan('notifiable:import-mail-log')->assertSuccessful();

    file_put_contents($this->logPath, substr($rejection, 80)."\n", FILE_APPEND);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 1);
    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->envelopeSender === 'partial@blocked.example';
    });
});

it('persists the offset between runs and never duplicates events', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true])->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 12);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 0 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 12);
});

it('imports only lines appended since the previous run', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true])->assertSuccessful();

    file_put_contents($this->logPath, implode("\n", [
        'Jun  9 13:00:00 mail postfix/smtpd[31001]: connect from mail.example.org[198.51.100.10]',
        smtpdRejectLine('late@blocked.example'),
    ])."\n", FILE_APPEND);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 13);
    Event::assertDispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->envelopeSender === 'late@blocked.example';
    });
});

it('restarts from the new file after log rotation', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true])->assertSuccessful();

    // Simulate logrotate: a freshly-created file (new inode) replaces the log.
    $replacement = sys_get_temp_dir().'/maillog-rotated-'.uniqid();
    copy(mailLogFixture('rotated.log'), $replacement);
    rename($replacement, $this->logPath);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 13);
});

it('restarts from the beginning when the log is truncated in place', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true])->assertSuccessful();

    // Same inode, but shorter than the stored offset (copytruncate-style rotation).
    file_put_contents($this->logPath, smtpdRejectLine('spammer@blocked.example', '04:00:05')."\n");

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 13);
});

it('leaves a partially written final line for the next run', function () {
    Event::fake();

    $rejection = smtpdRejectLine('spammer@blocked.example', '12:01:10');

    file_put_contents(
        $this->logPath,
        "Jun  9 12:00:01 mail postfix/smtpd[31001]: connect from mail.example.org[198.51.100.10]\n".substr($rejection, 0, 80)
    );

    $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true])
        ->expectsOutputToContain('Observed 0 SMTP-time rejection(s).')
        ->assertSuccessful();

    file_put_contents($this->logPath, substr($rejection, 80)."\n", FILE_APPEND);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 1);
});

it('does not replay already-dispatched lines after a listener throws mid-batch', function () {
    $dispatched = [];
    $shouldThrow = true;

    Event::listen(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) use (&$dispatched, &$shouldThrow) {
        $dispatched[] = $event->rejection->envelopeSender;

        if ($shouldThrow && $event->rejection->envelopeSender === 'second@blocked.example') {
            throw new RuntimeException('listener failure');
        }
    });

    file_put_contents($this->logPath, implode("\n", [
        smtpdRejectLine('first@blocked.example'),
        smtpdRejectLine('second@blocked.example'),
        smtpdRejectLine('third@blocked.example'),
    ])."\n");

    $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true])
        ->expectsOutputToContain('Import aborted')
        ->assertFailed();

    expect($dispatched)->toBe(['first@blocked.example', 'second@blocked.example']);

    $shouldThrow = false;

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 2 SMTP-time rejection(s).')
        ->assertSuccessful();

    // The first line is never replayed; only the in-flight line is
    // re-dispatched — the documented minimal duplicate window.
    expect($dispatched)->toBe([
        'first@blocked.example',
        'second@blocked.example',
        'second@blocked.example',
        'third@blocked.example',
    ]);
});

it('fails without losing rejections when the offset cannot be persisted', function () {
    Event::fake();

    file_put_contents($this->logPath, smtpdRejectLine('first@blocked.example')."\n");

    $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true])->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 1);

    file_put_contents($this->logPath, smtpdRejectLine('second@blocked.example')."\n", FILE_APPEND);

    chmod($this->offsetDirectory, 0555);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Import aborted')
        ->assertFailed();

    chmod($this->offsetDirectory, 0755);

    // The rejection whose offset write failed is re-dispatched on the next
    // run — at-least-once, never lost.
    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Observed 1 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 3);

    expect(Event::dispatched(SmtpRejectionObserved::class, function (SmtpRejectionObserved $event) {
        return $event->rejection->envelopeSender === 'first@blocked.example';
    }))->toHaveCount(1);
});

it('fails when the offset directory cannot be created', function () {
    Event::fake();

    copy(mailLogFixture('mixed.log'), $this->logPath);

    // A file where the offset directory should be makes mkdir fail.
    file_put_contents($this->offsetDirectory, 'not a directory');

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('Import aborted')
        ->assertFailed();

    Event::assertNotDispatched(SmtpRejectionObserved::class);
});

it('fails with guidance when the mail log is missing or unreadable', function () {
    Event::fake();

    unlink($this->logPath);

    $this->artisan('notifiable:import-mail-log')
        ->expectsOutputToContain('missing or not readable')
        ->assertFailed();

    Event::assertNotDispatched(SmtpRejectionObserved::class);
});

it('skips an isolated run while another import holds the command mutex', function () {
    Event::fake();

    config()->set('cache.default', 'array');

    copy(mailLogFixture('mixed.log'), $this->logPath);

    $mutex = app(CacheCommandMutex::class);
    $command = app(ImportMailLogCommand::class);

    expect($mutex->create($command))->toBeTrue();

    try {
        $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true, '--isolated' => true])
            ->expectsOutputToContain('already running')
            ->assertSuccessful();
    } finally {
        $mutex->forget($command);
    }

    Event::assertNotDispatched(SmtpRejectionObserved::class);

    // Once the mutex is free, an isolated run imports normally.
    $this->artisan('notifiable:import-mail-log', ['--from-beginning' => true, '--isolated' => true])
        ->expectsOutputToContain('Observed 12 SMTP-time rejection(s).')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SmtpRejectionObserved::class, 12);
});
