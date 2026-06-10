<?php

namespace Notifiable\ReceiveEmail\Console\Commands\Concerns;

use Illuminate\Support\Facades\Process;
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
}
