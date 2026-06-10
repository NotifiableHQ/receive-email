<?php

use Illuminate\Support\Facades\Process;
use Notifiable\ReceiveEmail\Support\PostfixDirectory;

beforeEach(function () {
    $this->postfixDir = sys_get_temp_dir().'/postfix-sync-test-'.uniqid();
    mkdir($this->postfixDir, 0755, true);

    PostfixDirectory::$path = $this->postfixDir;

    $this->whitelistCheck = 'check_sender_access hash:'.$this->postfixDir.'/notifiable_sender_whitelist';
    $this->blacklistCheck = 'check_sender_access hash:'.$this->postfixDir.'/notifiable_sender_blacklist';

    // The in-memory main.cf behind the postconf fakes; parameters report the
    // value an earlier `postconf -e` stored, so re-runs see their own writes.
    $this->postconf = (object) ['params' => [], 'services' => []];

    Process::fake([
        ...fakePostconf($this->postconf),
        'postmap *' => fakePostmap(),
        'postfix check' => Process::result(),
        'systemctl reload postfix' => Process::result(),
        '*' => Process::result(),
    ]);
});

afterEach(function () {
    PostfixDirectory::$path = PostfixDirectory::DEFAULT;

    foreach (glob($this->postfixDir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->postfixDir);
});

it('renders empty maps and the open restriction shape when no lists are configured', function () {
    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    expect(file_exists($this->postfixDir.'/notifiable_sender_whitelist'))->toBeTrue();
    expect(file_exists($this->postfixDir.'/notifiable_sender_blacklist'))->toBeTrue();

    Process::assertRan("postconf -e 'smtpd_sender_restrictions = reject_non_fqdn_sender, reject_unknown_sender_domain'");

    Process::assertRan('postmap hash:'.$this->postfixDir.'/notifiable_sender_whitelist.tmp');
    Process::assertRan('postmap hash:'.$this->postfixDir.'/notifiable_sender_blacklist.tmp');
});

it('renders a blacklist-only configuration with the open restriction shape', function () {
    config()->set('receive_email.sender-address-blacklist', ['spammer@bad.test']);
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    expect(file_get_contents($this->postfixDir.'/notifiable_sender_blacklist'))
        ->toContain("spammer@bad.test\tREJECT")
        ->toContain("bad.test\tREJECT");

    Process::assertRan(
        "postconf -e 'smtpd_sender_restrictions = {$this->blacklistCheck}, reject_non_fqdn_sender, reject_unknown_sender_domain'"
    );
});

it('renders a whitelist-only configuration with the default-reject restriction shape', function () {
    config()->set('receive_email.sender-address-whitelist', ['alerts@trusted.test']);
    config()->set('receive_email.sender-domain-whitelist', ['trusted.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    expect(file_get_contents($this->postfixDir.'/notifiable_sender_whitelist'))
        ->toContain("alerts@trusted.test\tOK")
        ->toContain("trusted.test\tOK");

    Process::assertRan(
        "postconf -e 'smtpd_sender_restrictions = {$this->whitelistCheck}, reject_non_fqdn_sender, reject_unknown_sender_domain, reject'"
    );
});

it('exempts the null Envelope Sender in the whitelist map', function () {
    config()->set('receive_email.sender-domain-whitelist', ['trusted.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    // RFC 5321 §4.5.5: the default-reject shape must keep MAIL FROM:<>
    // (remote bounces/DSNs) deliverable; `<>` is the
    // smtpd_null_access_lookup_key Postfix uses for the null sender.
    expect(file_get_contents($this->postfixDir.'/notifiable_sender_whitelist'))
        ->toContain("<>\tOK")
        ->toContain("trusted.test\tOK");
});

it('renders no null-sender exemption when no whitelist is configured', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    expect(file_get_contents($this->postfixDir.'/notifiable_sender_whitelist'))->not->toContain('<>');
    expect(file_get_contents($this->postfixDir.'/notifiable_sender_blacklist'))->not->toContain('<>');
});

it('renders a mixed configuration with the blacklist consulted before the whitelist', function () {
    config()->set('receive_email.sender-domain-whitelist', ['trusted.test']);
    config()->set('receive_email.sender-address-blacklist', ['spammer@trusted.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    Process::assertRan(
        "postconf -e 'smtpd_sender_restrictions = {$this->blacklistCheck}, {$this->whitelistCheck}, "
        ."reject_non_fqdn_sender, reject_unknown_sender_domain, reject'"
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
    Process::assertRanTimes(
        "postconf -e 'smtpd_sender_restrictions = {$this->blacklistCheck}, reject_non_fqdn_sender, reject_unknown_sender_domain'",
        1
    );
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

    Process::assertRan(
        "postconf -e 'smtpd_sender_restrictions = {$this->whitelistCheck}, reject_non_fqdn_sender, reject_unknown_sender_domain, reject'"
    );

    expect($this->postconf->params['smtpd_sender_restrictions'])
        ->toBe("{$this->whitelistCheck}, reject_non_fqdn_sender, reject_unknown_sender_domain, reject");
});

it('skips the Postfix reload with --no-reload', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    $this->artisan('notifiable:sync-postfix', ['--no-reload' => true])
        ->expectsOutputToContain('Skipping the Postfix reload (--no-reload)')
        ->assertSuccessful();

    Process::assertDidntRun('systemctl reload postfix');
});

it('reloads on the next run after a failed reload instead of reporting already up to date', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    Process::fake([
        'systemctl reload postfix' => Process::result(
            errorOutput: 'Job for postfix.service failed because the control process exited with error code.',
            exitCode: 1,
        ),
    ]);

    // The maps and restrictions are written before the reload; the failed
    // reload must not strand them as inactive-but-"up to date".
    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('Failed to reload Postfix')
        ->assertFailed();

    Process::fake([
        'systemctl reload postfix' => Process::result(),
    ]);

    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('reload pending')
        ->assertSuccessful();

    Process::assertRanTimes('systemctl reload postfix', 2);

    // The successful reload clears the pending state: the next run is a
    // no-op again.
    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('already up to date')
        ->assertSuccessful();

    Process::assertRanTimes('systemctl reload postfix', 2);
});

it('does not reload when postfix check fails (verify-before-activate)', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    Process::fake([
        'postfix check' => Process::result(errorOutput: 'main.cf: undefined parameter', exitCode: 1),
    ]);

    // Every reload activates whatever is in the Postfix directory, so each
    // one is gated on a fresh `postfix check`.
    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('`postfix check` failed')
        ->assertFailed();

    Process::assertDidntRun('systemctl reload postfix');

    // The marker persists so the next run retries activation once the
    // configuration verifies.
    expect(file_exists($this->postfixDir.'/notifiable_reload_pending'))->toBeTrue();

    Process::fake([
        'postfix check' => Process::result(),
    ]);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    Process::assertRanTimes('systemctl reload postfix', 1);
    expect(file_exists($this->postfixDir.'/notifiable_reload_pending'))->toBeFalse();
});

it('reloads on the next run after --no-reload wrote configuration', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    $this->artisan('notifiable:sync-postfix', ['--no-reload' => true])->assertSuccessful();

    // Nothing is left to write, but the written configuration was never
    // activated, so the reload-enabled run must reload.
    $this->artisan('notifiable:sync-postfix')
        ->doesntExpectOutputToContain('already up to date')
        ->assertSuccessful();

    Process::assertRanTimes('systemctl reload postfix', 1);
});

it('aborts when a list entry contains whitespace', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad domain.test']);

    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('Invalid sender-domain-blacklist entry')
        ->assertFailed();
});

it('aborts when a list entry is the null sender token', function () {
    // A blacklisted `<>` would reject the remote bounces and DSNs that the
    // whitelist exemption guarantees deliverable (RFC 5321 §4.5.5).
    config()->set('receive_email.sender-address-blacklist', ['<>']);

    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain("Invalid sender-address-blacklist entry: '<>'. The null Envelope Sender cannot be listed")
        ->assertFailed();

    Process::assertNothingRan();
});

it('aborts when a whitelist entry is the null sender token', function () {
    config()->set('receive_email.sender-address-whitelist', ['<>']);

    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('Invalid sender-address-whitelist entry')
        ->assertFailed();

    Process::assertNothingRan();
});

it('aborts when a list entry starts with a comment character', function () {
    // postmap treats #-prefixed lines as comments: the entry would silently
    // fail open on a blacklist and fail closed on a whitelist.
    config()->set('receive_email.sender-domain-blacklist', ['#bad.test']);

    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('access-map comments that Postfix silently ignores')
        ->assertFailed();

    Process::assertNothingRan();
});

it('aborts when a whitelist entry starts with a comment character', function () {
    config()->set('receive_email.sender-domain-whitelist', ['#trusted.test']);

    $this->artisan('notifiable:sync-postfix')
        ->expectsOutputToContain('Invalid sender-domain-whitelist entry')
        ->assertFailed();

    Process::assertNothingRan();
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

it('rebuilds the access map on the next run after a failed postmap', function () {
    config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

    $this->artisan('notifiable:sync-postfix')->assertSuccessful();

    // The list changes but the indexed rebuild fails: the live map must
    // keep its previous content so the next run retries the build instead
    // of trusting the stale .db and reporting "already up to date".
    config()->set('receive_email.sender-domain-blacklist', ['bad.test', 'worse.test']);

    Process::fake([
        'postmap *' => Process::result(errorOutput: 'postmap: fatal: out of memory', exitCode: 1),
    ]);

    $this->artisan('notifiable:sync-postfix')->assertFailed();

    expect(file_get_contents($this->postfixDir.'/notifiable_sender_blacklist'))
        ->not->toContain('worse.test');

    Process::fake([
        'postmap *' => fakePostmap(),
    ]);

    $this->artisan('notifiable:sync-postfix')
        ->doesntExpectOutputToContain('already up to date')
        ->assertSuccessful();

    expect(file_get_contents($this->postfixDir.'/notifiable_sender_blacklist'))
        ->toContain("worse.test\tREJECT");

    Process::assertRan('systemctl reload postfix');
});
