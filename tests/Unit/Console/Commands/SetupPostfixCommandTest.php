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
