<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Notifiable\ReceiveEmail\Console\Commands\Concerns\ManagesPostfixConfig;
use Notifiable\ReceiveEmail\Support\PostfixDirectory;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * Compiles the built-in sender whitelist/blacklist config lists into Postfix
 * check_sender_access maps keyed on the Envelope Sender, and rewrites
 * smtpd_sender_restrictions to enforce them as SMTP-time Rejection (ADR-0001).
 * This command owns the smtpd_sender_restrictions parameter.
 */
class SyncPostfixCommand extends ConsoleCommand
{
    use ManagesPostfixConfig;

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

            $changed = $this->writeAccessMap(self::WHITELIST_MAP, $this->withNullSenderExemption($whitelist), 'OK');
            $changed = $this->writeAccessMap(self::BLACKLIST_MAP, $blacklist, 'REJECT') || $changed;
            $changed = $this->syncSenderRestrictions($whitelist !== [], $blacklist !== []) || $changed;

            if ($changed) {
                $this->markPostfixReloadPending();
            }

            if (! $changed && ! $this->postfixReloadIsPending()) {
                $this->info('The Postfix sender access configuration is already up to date.');
            } elseif ($this->option('no-reload')) {
                $this->line('Skipping the Postfix reload (--no-reload).');
            } else {
                if (! $changed) {
                    $this->line('A previous run left a Postfix reload pending; reloading.');
                }

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

                // `<>` is Postfix's lookup key for the null Envelope Sender.
                // Listing it would override the automatic whitelist
                // exemption or, on a blacklist, reject the remote bounces
                // and DSNs that RFC 5321 requires stay deliverable.
                if ($entry === '<>') {
                    throw new RuntimeException(
                        "Invalid {$key} entry: '<>'. The null Envelope Sender cannot be listed: "
                        .'RFC 5321 requires it to stay deliverable, and the whitelist already exempts it automatically.'
                    );
                }

                // postmap treats #-prefixed lines as comments, so such an
                // entry would silently fail open on a blacklist and fail
                // closed on a whitelist.
                if (str_starts_with($entry, '#')) {
                    throw new RuntimeException(
                        "Invalid {$key} entry: ".var_export($entry, true)
                        .". Entries starting with '#' are access-map comments that Postfix silently ignores."
                    );
                }

                $entries[] = mb_strtolower($entry);
            }
        }

        return array_values(array_unique($entries));
    }

    /**
     * Whitelist mode's default-reject shape would also reject `MAIL FROM:<>`,
     * but RFC 5321 §4.5.5 requires the null Envelope Sender — remote bounces
     * and delivery status notifications addressed to this domain — to stay
     * deliverable. Postfix looks the null sender up in access maps via
     * smtpd_null_access_lookup_key (default `<>`), so the whitelist map OKs
     * that key. The open shape used without a whitelist never rejects the
     * null sender, so no exemption is rendered there.
     *
     * @param  list<string>  $whitelist
     * @return list<string>
     */
    private function withNullSenderExemption(array $whitelist): array
    {
        return $whitelist === [] ? [] : array_values(array_unique(['<>', ...$whitelist]));
    }

    /**
     * Write an access map and rebuild its indexed form with postmap. The map
     * is written and indexed at a staging path and only renamed live after
     * postmap succeeds, so a failed build leaves the previous text in place
     * and the next run retries instead of mistaking the stale .db for
     * current. Returns whether the map content changed; an unchanged map is
     * rebuilt only when its indexed .db file is missing.
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

        if ($unchanged && is_file("{$path}.db")) {
            return false;
        }

        $staging = "{$path}.tmp";

        if (@file_put_contents($staging, $content) === false) {
            throw new RuntimeException("Failed to write file: {$staging}");
        }

        $postmap = Process::run("postmap hash:{$staging}");

        if (! $postmap->successful()) {
            @unlink($staging);
            @unlink("{$staging}.db");

            throw new RuntimeException(
                "`postmap hash:{$staging}` failed: ".trim($postmap->output().' '.$postmap->errorOutput())
            );
        }

        // The .db moves live before the text: interrupted here, a newer text
        // forces a rebuild on the next run, while a newer .db is harmless.
        if (! @rename("{$staging}.db", "{$path}.db") || ! @rename($staging, $path)) {
            throw new RuntimeException("Failed to move {$staging} into place at {$path}");
        }

        if (! $unchanged) {
            $this->line("Wrote {$path} (".count($entries).' entries)');
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
        return $this->setMainParameter(
            'smtpd_sender_restrictions',
            $this->renderSenderRestrictions($hasWhitelist, $hasBlacklist)
        );
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

        return implode(', ', $restrictions);
    }

    private function reloadPostfix(): void
    {
        // Verify before activating: the reload also activates configuration
        // a failed setup run left behind (its reload-pending marker survives
        // a postflight failure), so broken config must be caught here rather
        // than reloaded into service.
        $this->assertPostfixCheckPasses();

        $reload = Process::run('systemctl reload postfix');

        if (! $reload->successful()) {
            throw new RuntimeException(
                'Failed to reload Postfix: '.trim($reload->output().' '.$reload->errorOutput())
            );
        }

        $this->clearPostfixReloadPending();

        $this->info('Postfix reloaded.');
    }
}
