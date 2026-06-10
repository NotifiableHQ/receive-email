<?php

namespace Notifiable\ReceiveEmail\Console\Commands\Concerns;

use Illuminate\Support\Facades\Process;
use Notifiable\ReceiveEmail\Support\PostfixDirectory;
use RuntimeException;

/**
 * Writes Postfix configuration through postconf instead of editing the config
 * files directly. Hand-editing corrupts multi-line continuation values and
 * touches only the first occurrence of a duplicated parameter while Postfix
 * honors the last; postconf is the canonical editor and is immune to both.
 */
trait ManagesPostfixConfig
{
    /**
     * Records that configuration has been written but not yet activated by a
     * reload. Without it, a failed reload would strand the new
     * configuration: the next sync run would find nothing left to change,
     * report "already up to date", and never retry the reload. The marker
     * alone never authorizes activation — every reload path is gated on a
     * fresh `postfix check` (see assertPostfixCheckPasses), so a marker left
     * by a failed run cannot activate configuration that fails verification.
     */
    private const RELOAD_PENDING_MARKER = 'notifiable_reload_pending';

    /**
     * A reload activates whatever configuration is on disk — including
     * configuration an earlier failed run wrote but refused to activate —
     * so every reload path must pass a fresh `postfix check` first.
     */
    private function assertPostfixCheckPasses(): void
    {
        $check = Process::run('postfix check');

        if (! $check->successful()) {
            throw new RuntimeException(
                '`postfix check` failed; not reloading Postfix. The configuration written to '
                .PostfixDirectory::$path.' stays inactive until a later run passes verification: '
                .trim($check->output().' '.$check->errorOutput())
            );
        }
    }

    /**
     * Set a main.cf parameter via `postconf -e`, skipping the write when the
     * current value already matches. Returns whether the parameter changed.
     */
    private function setMainParameter(string $parameter, string $value): bool
    {
        $current = Process::run("postconf -h {$parameter}");

        if ($current->successful() && rtrim($current->output(), "\n") === $value) {
            return false;
        }

        $set = Process::run('postconf -e '.escapeshellarg("{$parameter} = {$value}"));

        if (! $set->successful()) {
            throw new RuntimeException(
                "Failed to set {$parameter} via postconf: ".trim($set->output().' '.$set->errorOutput())
            );
        }

        $this->line("Set {$parameter} = {$value}");

        return true;
    }

    /**
     * Set a master.cf service entry via `postconf -M`, skipping the write
     * when the current entry already matches. The service is addressed as
     * `name/type` (e.g. `smtp/inet`) and the definition is the full service
     * line. Returns whether the entry changed.
     */
    private function setMasterService(string $service, string $definition): bool
    {
        $current = Process::run("postconf -M {$service}");

        // postconf -M prints the entry column-aligned, so compare with the
        // whitespace collapsed.
        $normalized = preg_replace('/\s+/', ' ', trim($current->output())) ?? '';

        if ($current->successful() && $normalized === $definition) {
            return false;
        }

        $set = Process::run('postconf -M '.escapeshellarg("{$service}={$definition}"));

        if (! $set->successful()) {
            throw new RuntimeException(
                "Failed to set the {$service} master.cf service via postconf: ".trim($set->output().' '.$set->errorOutput())
            );
        }

        $this->line("Set master.cf service {$service} = {$definition}");

        return true;
    }

    private function markPostfixReloadPending(): void
    {
        $marker = PostfixDirectory::path(self::RELOAD_PENDING_MARKER);

        if (@file_put_contents($marker, "Managed by notifiable:sync-postfix; deleted after a successful Postfix reload.\n") === false) {
            throw new RuntimeException("Failed to write file: {$marker}");
        }
    }

    private function postfixReloadIsPending(): bool
    {
        return file_exists(PostfixDirectory::path(self::RELOAD_PENDING_MARKER));
    }

    private function clearPostfixReloadPending(): void
    {
        @unlink(PostfixDirectory::path(self::RELOAD_PENDING_MARKER));
    }
}
