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

    SetupPostfixCommand::$postfixDirectory = $this->postfixDir;

    putenv('SUDO_USER');

    Process::fake([
        'dpkg -l | grep postfix' => Process::result('ii  postfix  3.8.6-1  amd64  High-performance mail transport agent'),
        '*' => Process::result(),
    ]);
});

afterEach(function () {
    SetupPostfixCommand::$postfixDirectory = SetupPostfixCommand::POSTFIX_DIR;

    putenv('SUDO_USER');

    @unlink($this->postfixDir.'/main.cf');
    @unlink($this->postfixDir.'/master.cf');
    @rmdir($this->postfixDir);
});

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
