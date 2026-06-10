<?php

use Illuminate\Support\Facades\Process;
use Notifiable\ReceiveEmail\Support\PostfixDirectory;

beforeEach(function () {
    $this->postfixDir = sys_get_temp_dir().'/postfix-sync-test-'.uniqid();
    mkdir($this->postfixDir, 0755, true);

    file_put_contents($this->postfixDir.'/main.cf', "myhostname = mail.example.com\n");

    PostfixDirectory::$path = $this->postfixDir;

    $this->whitelistCheck = 'check_sender_access hash:'.$this->postfixDir.'/notifiable_sender_whitelist';
    $this->blacklistCheck = 'check_sender_access hash:'.$this->postfixDir.'/notifiable_sender_blacklist';

    Process::fake([
        'postmap *' => Process::result(),
        'systemctl reload postfix' => Process::result(),
        '*' => Process::result(),
    ]);
});

afterEach(function () {
    PostfixDirectory::$path = PostfixDirectory::DEFAULT;

    foreach (['main.cf', 'notifiable_sender_whitelist', 'notifiable_sender_blacklist'] as $file) {
        @unlink($this->postfixDir.'/'.$file);
    }
    @rmdir($this->postfixDir);
});

it('renders empty maps and the open restriction shape when no lists are configured', function () {
    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    expect(file_exists($this->postfixDir.'/notifiable_sender_whitelist'))->toBeTrue();
    expect(file_exists($this->postfixDir.'/notifiable_sender_blacklist'))->toBeTrue();

    $mainConfig = (string) file_get_contents($this->postfixDir.'/main.cf');

    expect($mainConfig)
        ->toContain('smtpd_sender_restrictions = reject_non_fqdn_sender, reject_unknown_sender_domain')
        ->not->toContain('check_sender_access');

    Process::assertRan('postmap hash:'.$this->postfixDir.'/notifiable_sender_whitelist');
    Process::assertRan('postmap hash:'.$this->postfixDir.'/notifiable_sender_blacklist');
});

it('renders a blacklist-only configuration with the open restriction shape', function () {
    config()->set('receive_email.sender-address-blacklist', ['spammer@bad.test']);
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    expect(file_get_contents($this->postfixDir.'/notifiable_sender_blacklist'))
        ->toContain("spammer@bad.test\tREJECT")
        ->toContain("bad.test\tREJECT");

    expect(file_get_contents($this->postfixDir.'/main.cf'))->toContain(
        "smtpd_sender_restrictions = {$this->blacklistCheck}, reject_non_fqdn_sender, reject_unknown_sender_domain\n"
    );
});

it('renders a whitelist-only configuration with the default-reject restriction shape', function () {
    config()->set('receive_email.sender-address-whitelist', ['alerts@trusted.test']);
    config()->set('receive_email.sender-domain-whitelist', ['trusted.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    expect(file_get_contents($this->postfixDir.'/notifiable_sender_whitelist'))
        ->toContain("alerts@trusted.test\tOK")
        ->toContain("trusted.test\tOK");

    expect(file_get_contents($this->postfixDir.'/main.cf'))->toContain(
        "smtpd_sender_restrictions = {$this->whitelistCheck}, reject_non_fqdn_sender, reject_unknown_sender_domain, reject\n"
    );
});

it('renders a mixed configuration with the blacklist consulted before the whitelist', function () {
    config()->set('receive_email.sender-domain-whitelist', ['trusted.test']);
    config()->set('receive_email.sender-address-blacklist', ['spammer@trusted.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    expect(file_get_contents($this->postfixDir.'/main.cf'))->toContain(
        "smtpd_sender_restrictions = {$this->blacklistCheck}, {$this->whitelistCheck}, "
        ."reject_non_fqdn_sender, reject_unknown_sender_domain, reject\n"
    );
});

it('lowercases and de-duplicates list entries', function () {
    config()->set('receive_email.sender-domain-blacklist', ['Bad.Test', 'bad.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    $blacklistMap = (string) file_get_contents($this->postfixDir.'/notifiable_sender_blacklist');

    expect(substr_count($blacklistMap, "bad.test\tREJECT"))->toBe(1);
});

it('does not rewrite or reload when re-run with unchanged config', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('already up to date')
        ->assertSuccessful();

    Process::assertRanTimes('systemctl reload postfix', 1);

    $mainConfig = (string) file_get_contents($this->postfixDir.'/main.cf');

    expect(substr_count($mainConfig, 'smtpd_sender_restrictions ='))->toBe(1);
});

it('reflects changed lists after a re-run', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);
    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    config()->set('receive_email.sender-domain-blacklist', []);
    config()->set('receive_email.sender-domain-whitelist', ['trusted.test']);
    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    expect(file_get_contents($this->postfixDir.'/notifiable_sender_blacklist'))
        ->not->toContain('bad.test');
    expect(file_get_contents($this->postfixDir.'/notifiable_sender_whitelist'))
        ->toContain("trusted.test\tOK");

    $mainConfig = (string) file_get_contents($this->postfixDir.'/main.cf');

    expect($mainConfig)->toContain(
        "smtpd_sender_restrictions = {$this->whitelistCheck}, reject_non_fqdn_sender, reject_unknown_sender_domain, reject\n"
    );
    expect(substr_count($mainConfig, 'smtpd_sender_restrictions ='))->toBe(1);
});

it('skips the Postfix reload with --no-reload', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    $this->artisan('notifiable:sync-postfix', ['--no-reload' => true])
        ->expectsOutputToContain('Skipping the Postfix reload (--no-reload)')
        ->assertSuccessful();

    Process::assertDidntRun('systemctl reload postfix');
});

it('aborts when a list entry contains whitespace', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad domain.test']);

    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('Invalid sender-domain-blacklist entry')
        ->assertFailed();
});

it('aborts when postmap fails', function () {
    Process::fake([
        'postmap *' => Process::result(errorOutput: 'postmap: fatal: bad string length', exitCode: 1),
    ]);

    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('failed')
        ->assertFailed();

    Process::assertDidntRun('systemctl reload postfix');
});
