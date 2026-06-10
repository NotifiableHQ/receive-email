<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Notifiable\ReceiveEmail\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific test case
| class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may need
| to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions.
| The "expect()" function gives you access to a set of "expectations" methods that you can
| use to assert different things. Of course, you may extend the Expectation API.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to
| your project that you don't want to repeat in every file. Here you can define custom
| functions to be used within your tests.
|
*/

/**
 * Process fakes for postconf, backed by an in-memory store of main.cf
 * parameters and master.cf services, so tests observe the same
 * read-compare-write behavior as a real postconf: `postconf -h` and
 * `postconf -M <service>` report what an earlier `-e`/`-M` write stored.
 *
 * @param  object{params: array<string, string>, services: array<string, string>}  $store
 * @return array<string, Closure>
 */
function fakePostconf(object $store): array
{
    return [
        'postconf -h *' => function (PendingProcess $process) use ($store) {
            $parameter = str((string) $process->command)->after('postconf -h ')->value();

            // The "\n" termination mirrors real postconf output and keeps
            // FakeProcessResult from collapsing a value of '0' to '' (its
            // normalizeOutput() treats any empty()-ish output as blank).
            return Process::result(($store->params[$parameter] ?? '')."\n");
        },
        'postconf -e *' => function (PendingProcess $process) use ($store) {
            $argument = trim(str((string) $process->command)->after('postconf -e ')->value(), "'");
            [$parameter, $value] = explode(' = ', $argument, 2);
            $store->params[$parameter] = $value;

            return Process::result();
        },
        'postconf -M *' => function (PendingProcess $process) use ($store) {
            $argument = str((string) $process->command)->after('postconf -M ')->value();

            if (! str_contains($argument, '=')) {
                return Process::result(($store->services[$argument] ?? '')."\n");
            }

            [$service, $definition] = explode('=', trim($argument, "'"), 2);
            $store->services[$service] = $definition;

            return Process::result();
        },
    ];
}

/**
 * Fakes a successful postmap run that, like the real one, builds the
 * indexed .db file next to the map it was given.
 */
function fakePostmap(): Closure
{
    return function (PendingProcess $process) {
        touch(str((string) $process->command)->after('hash:')->value().'.db');

        return Process::result();
    };
}
