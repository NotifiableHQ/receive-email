<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Notifiable\ReceiveEmail\Console\Commands\Concerns\EditsPostfixConfig;
use Notifiable\ReceiveEmail\Support\PostfixDirectory;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * Compiles the built-in sender whitelist/blacklist config lists into Postfix
 * check_sender_access maps keyed on the Envelope Sender, and rewrites
 * smtpd_sender_restrictions to enforce them as SMTP-time Rejection (ADR-0001).
 * This command owns the smtpd_sender_restrictions line.
 */
class SyncPostfixCommand extends ConsoleCommand
{
    use EditsPostfixConfig;

    public const WHITELIST_MAP = 'notifiable_sender_whitelist';

    public const BLACKLIST_MAP = 'notifiable_sender_blacklist';

    /** @var string */
    protected $signature = 'notifiable:sync-postfix
        {--no-reload : Write the maps and restrictions without reloading Postfix.}';

    /** @var string */
    protected $description = 'Sync the configured Envelope Sender lists into Postfix access maps and sender restrictions.';

    public function handle(): int
    {
        $this->info("\nSyncing Envelope Sender lists into Postfix access maps\n");

        try {
            $whitelist = $this->listEntries('sender-address-whitelist', 'sender-domain-whitelist');
            $blacklist = $this->listEntries('sender-address-blacklist', 'sender-domain-blacklist');

            $changed = $this->writeAccessMap(self::WHITELIST_MAP, $whitelist, 'OK');
            $changed = $this->writeAccessMap(self::BLACKLIST_MAP, $blacklist, 'REJECT') || $changed;
            $changed = $this->syncSenderRestrictions($whitelist !== [], $blacklist !== []) || $changed;

            if (! $changed) {
                $this->info('The Postfix sender access configuration is already up to date.');
            } elseif ($this->option('no-reload')) {
                $this->line('Skipping the Postfix reload (--no-reload).');
            } else {
                $this->reloadPostfix();
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Collect, validate, and normalize the entries of the given config lists.
     *
     * @return list<string>
     */
    private function listEntries(string ...$keys): array
    {
        $entries = [];

        foreach ($keys as $key) {
            foreach (Config::array("receive_email.{$key}", []) as $entry) {
                if (! is_string($entry) || ! preg_match('/^\S+$/', $entry)) {
                    throw new RuntimeException(
                        "Invalid {$key} entry: ".var_export($entry, true)
                        .'. Entries must be single tokens without whitespace.'
                    );
                }

                $entries[] = mb_strtolower($entry);
            }
        }

        return array_values(array_unique($entries));
    }

    /**
     * Write an access map and rebuild its indexed form with postmap. Returns
     * whether the map content changed; an unchanged map is rebuilt only when
     * its indexed .db file is missing.
     *
     * @param  list<string>  $entries
     */
    private function writeAccessMap(string $map, array $entries, string $action): bool
    {
        $path = PostfixDirectory::path($map);

        $lines = ['# Managed by notifiable:sync-postfix; manual edits will be overwritten.'];

        foreach ($entries as $entry) {
            $lines[] = "{$entry}\t{$action}";
        }

        $content = implode("\n", $lines)."\n";

        $unchanged = is_file($path) && file_get_contents($path) === $content;

        if (! $unchanged) {
            if (@file_put_contents($path, $content) === false) {
                throw new RuntimeException("Failed to write file: {$path}");
            }

            $this->line("Wrote {$path} (".count($entries).' entries)');
        }

        if (! $unchanged || ! is_file("{$path}.db")) {
            $postmap = Process::run("postmap hash:{$path}");

            if (! $postmap->successful()) {
                throw new RuntimeException(
                    "`postmap hash:{$path}` failed: ".trim($postmap->output().' '.$postmap->errorOutput())
                );
            }
        }

        return ! $unchanged;
    }

    /**
     * Rewrite smtpd_sender_restrictions for the configured lists: an open
     * policy when only blacklists exist, and a default-reject shape — reject
     * every sender not OK'd by a map — when a whitelist is present.
     */
    private function syncSenderRestrictions(bool $hasWhitelist, bool $hasBlacklist): bool
    {
        $mainConfig = $this->getConfigPath('main.cf');

        $line = $this->renderSenderRestrictions($hasWhitelist, $hasBlacklist);

        $content = file_get_contents($mainConfig);

        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$mainConfig}");
        }

        if (preg_match('/^smtpd_sender_restrictions = .*$/m', $content, $matches) && $matches[0] === $line) {
            return false;
        }

        $this->upsertOrEditLine($mainConfig, '/^smtpd_sender_restrictions = (.*)$/m', $line);

        return true;
    }

    private function renderSenderRestrictions(bool $hasWhitelist, bool $hasBlacklist): string
    {
        $restrictions = [];

        // The blacklist is consulted first so a blacklisted sender is
        // rejected even when a whitelist would also match it.
        if ($hasBlacklist) {
            $restrictions[] = 'check_sender_access hash:'.PostfixDirectory::path(self::BLACKLIST_MAP);
        }

        if ($hasWhitelist) {
            $restrictions[] = 'check_sender_access hash:'.PostfixDirectory::path(self::WHITELIST_MAP);
        }

        $restrictions[] = 'reject_non_fqdn_sender';
        $restrictions[] = 'reject_unknown_sender_domain';

        if ($hasWhitelist) {
            $restrictions[] = 'reject';
        }

        return 'smtpd_sender_restrictions = '.implode(', ', $restrictions);
    }

    private function reloadPostfix(): void
    {
        $reload = Process::run('systemctl reload postfix');

        if (! $reload->successful()) {
            throw new RuntimeException(
                'Failed to reload Postfix: '.trim($reload->output().' '.$reload->errorOutput())
            );
        }

        $this->info('Postfix reloaded.');
    }
}
