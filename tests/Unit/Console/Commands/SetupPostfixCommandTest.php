<?php

use Illuminate\Support\Facades\Process;
use Notifiable\ReceiveEmail\Console\Commands\SetupPostfixCommand;

function currentSystemUser(): string
{
    return function_exists('posix_geteuid')
        ? posix_getpwuid(posix_geteuid())['name'] ?? get_current_user()
        : get_current_user();
}

beforeEach(function () {
    $this->postfixDir = sys_get_temp_dir().'/postfix-test-'.uniqid();
    mkdir($this->postfixDir, 0755, true);

    file_put_contents($this->postfixDir.'/main.cf', "myhostname = old.example.com\n");
    file_put_contents($this->postfixDir.'/master.cf', "smtp      inet  n       -       y       -       -       smtpd\n");
    file_put_contents($this->postfixDir.'/os-release', "ID=ubuntu\nVERSION_ID=\"24.04\"\n");

    SetupPostfixCommand::$postfixDirectory = $this->postfixDir;
    SetupPostfixCommand::$osReleasePath = $this->postfixDir.'/os-release';
    SetupPostfixCommand::$effectiveUserId = 0;

    putenv('SUDO_USER');

    Process::fake([
        'dpkg -l | grep postfix' => Process::result('ii  postfix  3.8.6-1  amd64  High-performance mail transport agent'),
        'postfix check' => Process::result(),
        'postconf -x mydestination' => Process::result('mydestination = example.com, localhost.localdomain, localhost'),
        'DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python' => Process::result(),
        'DEBIAN_FRONTEND=noninteractive apt-get install -y spf-engine' => Process::result(),
        '*' => Process::result(),
    ]);
});

afterEach(function () {
    SetupPostfixCommand::$postfixDirectory = SetupPostfixCommand::POSTFIX_DIR;
    SetupPostfixCommand::$osReleasePath = '/etc/os-release';
    SetupPostfixCommand::$effectiveUserId = null;

    putenv('SUDO_USER');

    @chmod($this->postfixDir.'/main.cf', 0644);
    @unlink($this->postfixDir.'/main.cf');
    @unlink($this->postfixDir.'/master.cf');
    @unlink($this->postfixDir.'/os-release');
    @rmdir($this->postfixDir);
});

describe('pipe user resolution', function () {
    it('resolves the pipe user from the --user option over $SUDO_USER', function () {
        putenv('SUDO_USER=forge');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        expect(file_get_contents($this->postfixDir.'/master.cf'))->toContain('user=deploy');
    });

    it('resolves the pipe user from $SUDO_USER when --user is not given', function () {
        putenv('SUDO_USER=forge');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com'])
            ->assertSuccessful();

        expect(file_get_contents($this->postfixDir.'/master.cf'))->toContain('user=forge');
    });

    it('falls back to the current user without --user and $SUDO_USER', function () {
        $currentUser = currentSystemUser();

        if ($currentUser === 'root') {
            $this->markTestSkipped('Cannot exercise the current-user fallback when running as root.');
        }

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com'])
            ->assertSuccessful();

        expect(file_get_contents($this->postfixDir.'/master.cf'))->toContain("user={$currentUser}");
    });

    it('aborts before mutating anything when --user is root', function () {
        $mainBefore = file_get_contents($this->postfixDir.'/main.cf');
        $masterBefore = file_get_contents($this->postfixDir.'/master.cf');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'root'])
            ->expectsOutputToContain('The pipe command cannot run as root')
            ->assertFailed();

        Process::assertNothingRan();
        expect(file_get_contents($this->postfixDir.'/main.cf'))->toBe($mainBefore)
            ->and(file_get_contents($this->postfixDir.'/master.cf'))->toBe($masterBefore);
    });

    it('aborts before mutating anything when $SUDO_USER resolves to root', function () {
        putenv('SUDO_USER=root');

        $mainBefore = file_get_contents($this->postfixDir.'/main.cf');
        $masterBefore = file_get_contents($this->postfixDir.'/master.cf');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com'])
            ->expectsOutputToContain('The pipe command cannot run as root')
            ->assertFailed();

        Process::assertNothingRan();
        expect(file_get_contents($this->postfixDir.'/main.cf'))->toBe($mainBefore)
            ->and(file_get_contents($this->postfixDir.'/master.cf'))->toBe($masterBefore);
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

        $mainBefore = file_get_contents($this->postfixDir.'/main.cf');
        $masterBefore = file_get_contents($this->postfixDir.'/master.cf');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain('This command must be run as root')
            ->assertFailed();

        Process::assertNothingRan();
        expect(file_get_contents($this->postfixDir.'/main.cf'))->toBe($mainBefore)
            ->and(file_get_contents($this->postfixDir.'/master.cf'))->toBe($masterBefore);
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

        expect(file_get_contents($this->postfixDir.'/master.cf'))->toContain('user=deploy');
    });

    it('aborts with the failing path when a config file write fails', function () {
        chmod($this->postfixDir.'/main.cf', 0444);

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->expectsOutputToContain("Failed to write file: {$this->postfixDir}/main.cf")
            ->assertFailed();
    });
});

describe('postfix config values', function () {
    it('writes the queue lifetime values to main.cf', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        expect(file_get_contents($this->postfixDir.'/main.cf'))
            ->toContain('maximal_queue_lifetime = 5d')
            ->toContain('bounce_queue_lifetime = 0');
    });

    it('replaces existing queue lifetime values instead of appending', function () {
        file_put_contents(
            $this->postfixDir.'/main.cf',
            "myhostname = old.example.com\nmaximal_queue_lifetime = 1d\nbounce_queue_lifetime = 1d\n"
        );

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        $mainConfig = (string) file_get_contents($this->postfixDir.'/main.cf');

        expect($mainConfig)
            ->toContain('maximal_queue_lifetime = 5d')
            ->toContain('bounce_queue_lifetime = 0');
        expect(substr_count($mainConfig, 'maximal_queue_lifetime ='))->toBe(1);
        expect(substr_count($mainConfig, 'bounce_queue_lifetime ='))->toBe(1);
    });

    it('replaces the exclusion-list TLS protocols line with >=TLSv1.2 when TLS is configured', function () {
        file_put_contents(
            $this->postfixDir.'/main.cf',
            "myhostname = old.example.com\nsmtpd_tls_protocols = !SSLv2, !SSLv3, !TLSv1, !TLSv1.1\n"
        );
        file_put_contents($this->postfixDir.'/server.crt', 'cert');
        file_put_contents($this->postfixDir.'/server.key', 'key');

        $this->artisan('notifiable:setup-postfix', [
            'domain' => 'example.com',
            '--user' => 'deploy',
            '--tls-cert' => $this->postfixDir.'/server.crt',
            '--tls-key' => $this->postfixDir.'/server.key',
        ])->assertSuccessful();

        $mainConfig = (string) file_get_contents($this->postfixDir.'/main.cf');

        expect($mainConfig)->toContain('smtpd_tls_protocols = >=TLSv1.2');
        expect(substr_count($mainConfig, 'smtpd_tls_protocols ='))->toBe(1);

        @unlink($this->postfixDir.'/server.crt');
        @unlink($this->postfixDir.'/server.key');
    });

    it('keeps the queue lifetime and TLS protocol lines single across re-runs', function () {
        file_put_contents($this->postfixDir.'/server.crt', 'cert');
        file_put_contents($this->postfixDir.'/server.key', 'key');

        $arguments = [
            'domain' => 'example.com',
            '--user' => 'deploy',
            '--tls-cert' => $this->postfixDir.'/server.crt',
            '--tls-key' => $this->postfixDir.'/server.key',
        ];

        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();
        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();

        $mainConfig = (string) file_get_contents($this->postfixDir.'/main.cf');

        expect(substr_count($mainConfig, 'maximal_queue_lifetime ='))->toBe(1);
        expect(substr_count($mainConfig, 'bounce_queue_lifetime ='))->toBe(1);
        expect(substr_count($mainConfig, 'smtpd_tls_protocols ='))->toBe(1);

        @unlink($this->postfixDir.'/server.crt');
        @unlink($this->postfixDir.'/server.key');
    });
});

describe('postscreen topology', function () {
    it('puts postscreen on port 25 with smtpd as a pass-through service', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        $masterConfig = (string) file_get_contents($this->postfixDir.'/master.cf');

        expect($masterConfig)
            ->toContain('smtp inet n - - - 1 postscreen')
            ->toContain('smtpd pass - - - - - smtpd -o content_filter=notifiable:dummy')
            ->toContain('dnsblog unix - - - - 0 dnsblog')
            ->toContain('tlsproxy unix - - - - 0 tlsproxy')
            ->toContain('user=deploy argv=');

        expect(file_get_contents($this->postfixDir.'/main.cf'))
            ->toContain('postscreen_greet_action = enforce');
    });

    it('keeps the master.cf service entries single across re-runs', function () {
        $arguments = ['domain' => 'example.com', '--user' => 'deploy'];

        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();
        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();

        $masterConfig = (string) file_get_contents($this->postfixDir.'/master.cf');

        expect(preg_match_all('/^smtp\s+inet/m', $masterConfig))->toBe(1);
        expect(preg_match_all('/^smtpd\s+pass/m', $masterConfig))->toBe(1);
        expect(preg_match_all('/^dnsblog\s+unix/m', $masterConfig))->toBe(1);
        expect(preg_match_all('/^tlsproxy\s+unix/m', $masterConfig))->toBe(1);
        expect(preg_match_all('/^notifiable\s+unix/m', $masterConfig))->toBe(1);

        $mainConfig = (string) file_get_contents($this->postfixDir.'/main.cf');

        expect(substr_count($mainConfig, 'postscreen_greet_action ='))->toBe(1);
    });
});

describe('SPF verification', function () {
    it('configures SPF verification by default', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy'])
            ->assertSuccessful();

        Process::assertRan('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python');

        expect(file_get_contents($this->postfixDir.'/main.cf'))
            ->toContain('check_policy_service unix:private/policy-spf')
            ->toContain('policy-spf_time_limit = 3600s');
        expect(file_get_contents($this->postfixDir.'/master.cf'))
            ->toContain('policy-spf unix');
    });

    it('skips SPF verification with --without-spf', function () {
        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy', '--without-spf' => true])
            ->expectsOutputToContain('Skipping SPF verification (--without-spf)')
            ->assertSuccessful();

        Process::assertDidntRun('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python');
        Process::assertDidntRun('DEBIAN_FRONTEND=noninteractive apt-get install -y spf-engine');

        expect(file_get_contents($this->postfixDir.'/main.cf'))
            ->not->toContain('check_policy_service');
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

        expect(file_get_contents($this->postfixDir.'/main.cf'))
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

        expect(file_get_contents($this->postfixDir.'/main.cf'))->toContain('check_policy_service');

        $this->artisan('notifiable:setup-postfix', ['domain' => 'example.com', '--user' => 'deploy', '--without-spf' => true])
            ->assertSuccessful();

        expect(file_get_contents($this->postfixDir.'/main.cf'))->not->toContain('check_policy_service');
    });

    it('keeps the SPF configuration idempotent across re-runs', function () {
        $arguments = ['domain' => 'example.com', '--user' => 'deploy'];

        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();
        $this->artisan('notifiable:setup-postfix', $arguments)->assertSuccessful();

        $mainConfig = (string) file_get_contents($this->postfixDir.'/main.cf');
        $masterConfig = (string) file_get_contents($this->postfixDir.'/master.cf');

        expect(substr_count($mainConfig, 'policy-spf_time_limit ='))->toBe(1);
        expect(substr_count($mainConfig, 'check_policy_service'))->toBe(1);
        expect(preg_match_all('/^policy-spf\s+unix/m', $masterConfig))->toBe(1);
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
    });
});
