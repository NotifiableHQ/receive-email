<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Notifiable\ReceiveEmail\Console\Commands\SetupPostfixCommand;
use Notifiable\ReceiveEmail\Support\PostfixDirectory;

function currentSystemUser(): string
{
    return function_exists('posix_geteuid')
        ? posix_getpwuid(posix_geteuid())['name'] ?? get_current_user()
        : get_current_user();
}

beforeEach(function () {
    $this->postfixDir = sys_get_temp_dir().'/postfix-test-'.uniqid();
    mkdir($this->postfixDir, 0755, true);

    file_put_contents($this->postfixDir.'/os-release', "ID=ubuntu\nVERSION_ID=\"24.04\"\n");

    PostfixDirectory::$path = $this->postfixDir;
    SetupPostfixCommand::$osReleasePath = $this->postfixDir.'/os-release';
    SetupPostfixCommand::$effectiveUserId = 0;

    putenv('SUDO_USER');

    // The in-memory Postfix configuration behind the postconf fakes. Seeded
    // with the Ubuntu default for local_recipient_maps so setup observes a
    // value it must clear; unseeded parameters read as empty.
    $this->postconf = (object) [
        'params' => ['local_recipient_maps' => 'proxy:unix:passwd.byname $alias_maps'],
        'services' => [],
    ];

    Process::fake([
        'dpkg -l | grep postfix' => Process::result('ii  postfix  3.8.6-1  amd64  High-performance mail transport agent'),
        'apt-get update' => Process::result(),
        'DEBIAN_FRONTEND=noninteractive apt-get install -y postfix' => Process::result(),
        'DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python' => Process::result(),
        'DEBIAN_FRONTEND=noninteractive apt-get install -y spf-engine' => Process::result(),
        'postfix check' => Process::result(),
        'postconf -x mydestination' => Process::result('mydestination = example.com, localhost.localdomain, localhost'),
        ...fakePostconf($this->postconf),
        'postmap *' => fakePostmap(),
        'systemctl reload postfix' => Process::result(),
        '*' => Process::result(),
    ]);
});

afterEach(function () {
    PostfixDirectory::$path = PostfixDirectory::DEFAULT;
    SetupPostfixCommand::$osReleasePath = '/etc/os-release';
    SetupPostfixCommand::$effectiveUserId = null;

    putenv('SUDO_USER');

    foreach (glob($this->postfixDir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->postfixDir);
});

describe('pipe user resolution', function () {
    it('resolves the pipe user from the --user option over $SUDO_USER', function () {
        putenv('SUDO_USER=forge');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        expect($this->postconf->services['notifiable/unix'])->toContain('user=deploy');
    });

    it('resolves the pipe user from $SUDO_USER when --user is not given', function () {
        putenv('SUDO_USER=forge');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com'])
            ->assertSuccessful();

        expect($this->postconf->services['notifiable/unix'])->toContain('user=forge');
    });

    it('falls back to the current user without --user and $SUDO_USER', function () {
        $currentUser = currentSystemUser();

        if ($currentUser === 'root') {
            $this->markTestSkipped('Cannot exercise the current-user fallback when running as root.');
        }

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com'])
            ->assertSuccessful();

        expect($this->postconf->services['notifiable/unix'])->toContain("user={$currentUser}");
    });

    it('aborts before mutating anything when --user is root', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'root'])
            ->expectsOutputToContain('The pipe command cannot run as root')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('aborts before mutating anything when $SUDO_USER resolves to root', function () {
        putenv('SUDO_USER=root');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com'])
            ->expectsOutputToContain('The pipe command cannot run as root')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('rejects a pipe user containing invalid characters', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'bad user;rm'])
            ->expectsOutputToContain('Invalid system user')
            ->assertFailed();

        Process::assertNothingRan();
    });
});

describe('preflight checks', function () {
    it('aborts before mutating anything when not run as root', function () {
        SetupPostfixCommand::$effectiveUserId = 501;

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('This command must be run as root')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('aborts on a non-Ubuntu operating system', function () {
        file_put_contents($this->postfixDir.'/os-release', "ID=debian\nVERSION_ID=\"12\"\n");

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('Unsupported operating system: debian 12')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('aborts on Ubuntu older than 24.04', function () {
        file_put_contents($this->postfixDir.'/os-release', "ID=ubuntu\nVERSION_ID=\"22.04\"\n");

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('Unsupported operating system: ubuntu 22.04')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('aborts when the os-release file is missing', function () {
        unlink($this->postfixDir.'/os-release');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('Cannot detect the operating system')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('proceeds on an unsupported operating system when --force is passed', function () {
        file_put_contents($this->postfixDir.'/os-release', "ID=debian\nVERSION_ID=\"12\"\n");

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy', '--force' => true])
            ->expectsOutputToContain('Skipping the operating system check (--force)')
            ->assertSuccessful();

        expect($this->postconf->services['notifiable/unix'])->toContain('user=deploy');
    });

    it('installs Postfix when it is not already installed', function () {
        Process::fake([
            'dpkg -l | grep postfix' => Process::result(''),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        Process::assertRan('apt-get update');
        Process::assertRan('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix');
    });

    it('seeds debconf with bare unquoted values on a fresh install', function () {
        Process::fake([
            'dpkg -l | grep postfix' => Process::result(''),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        // debconf-set-selections stores the value verbatim (it is not a
        // shell): embedded quotes would reach /etc/mailname and
        // mydestination through the package's postinst.
        Process::assertRan("echo 'postfix postfix/mailname string example.com' | debconf-set-selections");
        Process::assertRan("echo 'postfix postfix/main_mailer_type string Internet Site' | debconf-set-selections");
        Process::assertRan('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix');
    });

    it('aborts before changing any configuration when apt-get update fails', function () {
        Process::fake([
            'dpkg -l | grep postfix' => Process::result(''),
            'apt-get update' => Process::result(
                errorOutput: 'E: Could not get lock /var/lib/apt/lists/lock',
                exitCode: 100,
            ),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('`apt-get update` failed')
            ->assertFailed();

        Process::assertDidntRun('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix');
        Process::assertDidntRun(fn (PendingProcess $process) => str_starts_with($process->command, 'postconf -e'));
    });

    it('aborts before changing any configuration when apt-get install fails', function () {
        Process::fake([
            'dpkg -l | grep postfix' => Process::result(''),
            'DEBIAN_FRONTEND=noninteractive apt-get install -y postfix' => Process::result(
                errorOutput: 'E: Unable to locate package postfix',
                exitCode: 100,
            ),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('`apt-get install -y postfix` failed')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $process) => str_starts_with($process->command, 'postconf -e'));
    });

    it('aborts before reloading when postconf fails to write a parameter', function () {
        Process::fake([
            'postconf -e *' => Process::result(errorOutput: 'postconf: fatal: open /etc/postfix/main.cf: Permission denied', exitCode: 1),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('Failed to set myhostname via postconf')
            ->assertFailed();

        Process::assertDidntRun('systemctl reload postfix');
    });

    it('aborts before reloading when postconf fails to write a master.cf service', function () {
        Process::fake([
            'postconf -M *' => Process::result(errorOutput: 'postconf: fatal: open /etc/postfix/master.cf: Permission denied', exitCode: 1),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('Failed to set the smtp/inet master.cf service via postconf')
            ->assertFailed();

        Process::assertDidntRun('systemctl reload postfix');
    });
});

describe('postfix config values', function () {
    it('writes the receive-only and hardening parameters via postconf', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        Process::assertRan("postconf -e 'myhostname = example.com'");
        Process::assertRan("postconf -e 'default_transport = error'");
        Process::assertRan("postconf -e 'relay_transport = error'");
        Process::assertRan("postconf -e 'local_recipient_maps = '");
        Process::assertRan("postconf -e 'message_size_limit = 26214400'");
        Process::assertRan('postconf -e \'smtpd_banner = $myhostname ESMTP\'');
        Process::assertRan("postconf -e 'disable_vrfy_command = yes'");
        Process::assertRan("postconf -e 'smtpd_client_connection_rate_limit = 30'");
        Process::assertRan("postconf -e 'smtpd_data_restrictions = reject_unauth_pipelining'");
        Process::assertRan("postconf -e 'smtpd_timeout = 120s'");
    });

    it('writes the queue lifetime values via postconf', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        // bounce_queue_lifetime governs all null-Envelope-Sender mail,
        // including accepted inbound DSNs, so it matches
        // maximal_queue_lifetime instead of deleting that mail after a
        // single tempfailed delivery attempt.
        Process::assertRan("postconf -e 'maximal_queue_lifetime = 5d'");
        Process::assertRan("postconf -e 'bounce_queue_lifetime = 5d'");
    });

    it('skips writing a parameter whose current value already matches', function () {
        $this->postconf->params['maximal_queue_lifetime'] = '5d';

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        Process::assertDidntRun("postconf -e 'maximal_queue_lifetime = 5d'");
        Process::assertRan("postconf -e 'bounce_queue_lifetime = 5d'");
    });

    it('repeats no parameter writes on an unchanged re-run', function () {
        $arguments = ['domain' => 'example.com', '--user' => 'deploy'];

        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();
        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();

        Process::assertRanTimes("postconf -e 'myhostname = example.com'", 1);
        Process::assertRanTimes("postconf -e 'maximal_queue_lifetime = 5d'", 1);
        Process::assertRanTimes("postconf -e 'bounce_queue_lifetime = 5d'", 1);
    });

    it('writes the TLS configuration when cert and key are provided', function () {
        // An exclusion-list value a pre-hardening install may carry; setup
        // must replace it with the minimum-version form.
        $this->postconf->params['smtpd_tls_protocols'] = '!SSLv2, !SSLv3, !TLSv1, !TLSv1.1';

        file_put_contents($this->postfixDir.'/server.crt', 'cert');
        file_put_contents($this->postfixDir.'/server.key', 'key');

        $this->artisan('notifiable:setup-postfix', [
            'domain' => 'example.com',
            '--user' => 'deploy',
            '--tls-cert' => $this->postfixDir.'/server.crt',
            '--tls-key' => $this->postfixDir.'/server.key',
        ])->assertSuccessful();

        Process::assertRan("postconf -e 'smtpd_tls_cert_file = {$this->postfixDir}/server.crt'");
        Process::assertRan("postconf -e 'smtpd_tls_key_file = {$this->postfixDir}/server.key'");
        Process::assertRan("postconf -e 'smtpd_tls_security_level = may'");
        Process::assertRan("postconf -e 'smtpd_tls_protocols = >=TLSv1.2'");
        Process::assertRan("postconf -e 'smtp_tls_security_level = none'");
    });

    it('writes no TLS parameters without cert and key', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('TLS is not configured')
            ->assertSuccessful();

        Process::assertDidntRun(fn (PendingProcess $process) => str_contains($process->command, 'smtpd_tls_cert_file'));
    });
});

describe('postscreen topology', function () {
    it('puts postscreen on port 25 with smtpd as a pass-through service', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        Process::assertRan("postconf -M 'smtp/inet=smtp inet n - - - 1 postscreen'");
        Process::assertRan("postconf -M 'smtpd/pass=smtpd pass - - - - - smtpd -o content_filter=notifiable:dummy'");
        Process::assertRan("postconf -M 'dnsblog/unix=dnsblog unix - - - - 0 dnsblog'");
        Process::assertRan("postconf -M 'tlsproxy/unix=tlsproxy unix - - - - 0 tlsproxy'");
        Process::assertRan("postconf -e 'postscreen_greet_action = enforce'");

        expect($this->postconf->services['notifiable/unix'])->toContain('user=deploy null_sender= argv=');
    });

    it('skips a master.cf write when the column-aligned entry already matches', function () {
        // postconf -M prints entries column-aligned; the comparison must
        // recognize the entry as current regardless of whitespace.
        $this->postconf->services['smtp/inet'] = 'smtp       inet  n       -       -       -       1       postscreen';

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        Process::assertDidntRun("postconf -M 'smtp/inet=smtp inet n - - - 1 postscreen'");
    });

    it('repeats no master.cf writes on an unchanged re-run', function () {
        $arguments = ['domain' => 'example.com', '--user' => 'deploy'];

        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();
        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();

        Process::assertRanTimes("postconf -M 'smtp/inet=smtp inet n - - - 1 postscreen'", 1);
        Process::assertRanTimes("postconf -M 'dnsblog/unix=dnsblog unix - - - - 0 dnsblog'", 1);
        Process::assertRanTimes(
            fn (PendingProcess $process) => str_starts_with($process->command, "postconf -M 'notifiable/unix="),
            1
        );
    });
});

describe('envelope capture', function () {
    it('writes the pipe transport with the envelope macros and the empty null_sender attribute', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        $transport = $this->postconf->services['notifiable/unix'];

        // null_sender= (empty) hands MAIL FROM:<> to the pipe as an empty
        // string, never the literal MAILER-DAEMON Postfix substitutes by
        // default, so the null Envelope Sender can store as null.
        expect($transport)
            ->toContain(' null_sender= argv=')
            ->toEndWith('notifiable:receive-email ${sender} ${client_address} ${queue_id} ${recipient}');
    });

    it('keeps the pipe transport write idempotent across re-runs', function () {
        $arguments = ['domain' => 'example.com', '--user' => 'deploy'];

        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();
        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();

        Process::assertRanTimes(
            fn (PendingProcess $process) => str_starts_with($process->command, "postconf -M 'notifiable/unix="),
            1
        );

        expect($this->postconf->services['notifiable/unix'])
            ->toContain(' null_sender= argv=')
            ->toEndWith('${sender} ${client_address} ${queue_id} ${recipient}');
    });

    it('leaves rows message-scoped: no destination_recipient_limit is written', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        // One delivery = one row carrying every Envelope Recipient;
        // destination_recipient_limit = 1 would split it per recipient.
        Process::assertDidntRun(
            fn (PendingProcess $process) => str_contains($process->command, 'destination_recipient_limit')
        );
    });
});

describe('SPF verification', function () {
    it('configures SPF verification by default', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        Process::assertRan('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python');

        Process::assertRan(
            "postconf -e 'smtpd_recipient_restrictions = permit_mynetworks, reject_non_fqdn_recipient, "
            ."reject_unknown_recipient_domain, reject_unauth_destination, check_policy_service unix:private/policy-spf'"
        );
        Process::assertRan("postconf -e 'policy-spf_time_limit = 3600s'");
        Process::assertRan("postconf -M 'policy-spf/unix=policy-spf unix - n n - 0 spawn user=policyd-spf argv=/usr/bin/policyd-spf'");
    });

    it('skips SPF verification with --without-spf', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy', '--without-spf' => true])
            ->expectsOutputToContain('Skipping SPF verification (--without-spf)')
            ->assertSuccessful();

        Process::assertDidntRun('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python');
        Process::assertDidntRun('DEBIAN_FRONTEND=noninteractive apt-get install -y spf-engine');

        expect($this->postconf->params['smtpd_recipient_restrictions'])->not->toContain('check_policy_service');
    });

    it('falls back to spf-engine when postfix-policyd-spf-python is unavailable', function () {
        Process::fake([
            'DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python' => Process::result(
                errorOutput: 'E: Unable to locate package postfix-policyd-spf-python',
                exitCode: 100,
            ),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        Process::assertRan('DEBIAN_FRONTEND=noninteractive apt-get install -y spf-engine');

        expect($this->postconf->params['smtpd_recipient_restrictions'])
            ->toContain('check_policy_service unix:private/policy-spf');
    });

    it('aborts with a clear error when no SPF policy daemon package can be installed', function () {
        Process::fake([
            'DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python' => Process::result(
                errorOutput: 'E: Unable to locate package postfix-policyd-spf-python',
                exitCode: 100,
            ),
            'DEBIAN_FRONTEND=noninteractive apt-get install -y spf-engine' => Process::result(
                errorOutput: 'E: Unable to locate package spf-engine',
                exitCode: 100,
            ),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('Failed to install the SPF policy daemon')
            ->assertFailed();
    });

    it('removes the SPF policy check when re-run with --without-spf', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        expect($this->postconf->params['smtpd_recipient_restrictions'])->toContain('check_policy_service');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy', '--without-spf' => true])
            ->assertSuccessful();

        expect($this->postconf->params['smtpd_recipient_restrictions'])->not->toContain('check_policy_service');
    });

    it('keeps the SPF configuration idempotent across re-runs', function () {
        $arguments = ['domain' => 'example.com', '--user' => 'deploy'];

        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();
        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();

        Process::assertRanTimes("postconf -e 'policy-spf_time_limit = 3600s'", 1);
        Process::assertRanTimes("postconf -M 'policy-spf/unix=policy-spf unix - n n - 0 spawn user=policyd-spf argv=/usr/bin/policyd-spf'", 1);
    });
});

describe('sender access map sync', function () {
    it('syncs the Envelope Sender access maps once during setup', function () {
        config()->set('receive_email.sender-address-blacklist', ['spammer@bad.test']);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        expect(file_get_contents($this->postfixDir.'/notifiable_sender_blacklist'))
            ->toContain("spammer@bad.test\tREJECT");

        Process::assertRan(
            "postconf -e 'smtpd_sender_restrictions = check_sender_access hash:{$this->postfixDir}/notifiable_sender_blacklist, "
            ."reject_non_fqdn_sender, reject_unknown_sender_domain'"
        );

        Process::assertRan('postmap hash:'.$this->postfixDir.'/notifiable_sender_blacklist.tmp');

        // Setup reloads Postfix itself after the postflight checks; the
        // inner sync must not reload a not-yet-verified configuration.
        Process::assertRanTimes('systemctl reload postfix', 1);
    });
});

describe('postflight checks', function () {
    it('aborts before reloading Postfix when postfix check fails', function () {
        Process::fake([
            'postfix check' => Process::result(errorOutput: 'main.cf: undefined parameter', exitCode: 1),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('`postfix check` failed')
            ->assertFailed();

        Process::assertDidntRun('systemctl reload postfix');
    });

    it('aborts before reloading Postfix when mydestination does not cover the receiving domain', function () {
        Process::fake([
            'postconf -x mydestination' => Process::result('mydestination = foreign.example.org, localhost'),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('mydestination does not include example.com')
            ->assertFailed();

        Process::assertDidntRun('systemctl reload postfix');
    });

    it('reloads Postfix when the postflight checks pass', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        Process::assertRan('postfix check');
        Process::assertRan('postconf -x mydestination');
        Process::assertRan('systemctl reload postfix');

        // The reload also activates the inner sync's --no-reload writes, so
        // no reload-pending marker may remain.
        expect(file_exists($this->postfixDir.'/notifiable_reload_pending'))->toBeFalse();
    });

    it('fails when the Postfix reload fails', function () {
        Process::fake([
            'systemctl reload postfix' => Process::result(
                errorOutput: 'Job for postfix.service failed because the control process exited with error code.',
                exitCode: 1,
            ),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('Failed to reload Postfix')
            ->assertFailed();

        // The inner sync's writes were never activated; the marker makes the
        // next notifiable:sync-postfix run retry the reload.
        expect(file_exists($this->postfixDir.'/notifiable_reload_pending'))->toBeTrue();
    });

    it('does not let a later sync activate configuration whose postflight verification failed', function () {
        config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

        Process::fake([
            'postfix check' => Process::result(errorOutput: 'main.cf: undefined parameter', exitCode: 1),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('`postfix check` failed')
            ->assertFailed();

        // The inner sync wrote its maps before verification failed, so the
        // reload-pending marker survives the failed setup run.
        expect(file_exists($this->postfixDir.'/notifiable_reload_pending'))->toBeTrue();

        // The deploy-hook sync sees the pending reload, but must not
        // activate the configuration setup refused to activate.
        $this->artisan('notifiable:sync-postfix')
            ->expectsOutputToContain('`postfix check` failed')
            ->assertFailed();

        Process::assertDidntRun('systemctl reload postfix');
        expect(file_exists($this->postfixDir.'/notifiable_reload_pending'))->toBeTrue();

        // Once the configuration verifies again, the pending reload may
        // proceed and activate it.
        Process::fake([
            'postfix check' => Process::result(),
        ]);

        $this->artisan('notifiable:sync-postfix')->assertSuccessful();

        Process::assertRanTimes('systemctl reload postfix', 1);
        expect(file_exists($this->postfixDir.'/notifiable_reload_pending'))->toBeFalse();
    });

    it('warns that written configuration is not active when postflight verification fails', function () {
        config()->set('receive_email.sender-domain-blacklist', ['bad.test']);

        Process::fake([
            'postfix check' => Process::result(errorOutput: 'main.cf: undefined parameter', exitCode: 1),
        ]);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('not yet active')
            ->assertFailed();
    });
});
