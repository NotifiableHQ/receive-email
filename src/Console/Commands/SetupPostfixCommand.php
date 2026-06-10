<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\Process;
use Notifiable\ReceiveEmail\Console\Commands\Concerns\ManagesPostfixConfig;
use Notifiable\ReceiveEmail\Support\PostfixDirectory;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

class SetupPostfixCommand extends ConsoleCommand
{
    use ManagesPostfixConfig;

    public const POSTFIX_DIR = PostfixDirectory::DEFAULT;

    private const RECIPIENT_RESTRICTIONS = 'permit_mynetworks, reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination';

    /**
     * The os-release file consulted by the operating system preflight check.
     * Overridable in tests.
     */
    public static string $osReleasePath = '/etc/os-release';

    /**
     * Overrides the effective user id consulted by the root preflight check.
     * For tests only; null means the real effective user id.
     */
    public static ?int $effectiveUserId = null;

    private const DOMAIN_PATTERN = '/^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/';

    /** @var string */
    protected $signature = 'notifiable:setup-postfix
        {domain : The domain where to receive emails from.}
        {--user= : The system user to run the pipe command as. Defaults to $SUDO_USER, then the current user.}
        {--tls-cert= : Path to the TLS certificate file (PEM format).}
        {--tls-key= : Path to the TLS private key file (PEM format).}
        {--without-spf : Skip SPF verification setup (not recommended; SPF is what makes the Envelope Sender trustworthy).}
        {--force : Skip the operating system requirement check (Ubuntu 24.04+).}';

    /** @var string */
    protected $description = 'Install and Configure Postfix to receive emails.';

    public function handle(): int
    {
        $this->info("\nSetting up Postfix\n");

        $this->warn('THIS SCRIPT WILL MODIFY THE POSTFIX CONFIGURATION FILES!');

        /** @var string $domain */
        $domain = $this->argument('domain');

        if (! preg_match(self::DOMAIN_PATTERN, $domain)) {
            $this->error("Invalid domain: {$domain}");

            return Command::FAILURE;
        }

        try {
            $this->assertRunningAsRoot();
            $this->assertSupportedOperatingSystem();
            $user = $this->resolvePipeUser();

            $this->installPostfix($domain);
            $this->configureMainConfigFile($domain);
            $this->configureMasterConfigFile($user);

            if ($this->option('without-spf')) {
                $this->info("\nSkipping SPF verification (--without-spf): the Envelope Sender of inbound mail will not be verified.");
            } else {
                $this->configureSPF();
            }

            $this->info("\nFor DKIM/DMARC verification, consider rspamd.");

            $this->syncSenderAccessMaps();
            $this->verifyPostfixConfiguration($domain);
            $this->reloadPostfix();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            if ($this->postfixReloadIsPending()) {
                $this->warn(
                    'Setup wrote configuration to '.PostfixDirectory::$path.' that is not yet active. '
                    .'The next notifiable:sync-postfix run (e.g. from a deploy hook) will activate it '
                    .'only after `postfix check` passes.'
                );
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function installPostfix(string $domain): void
    {
        $this->info("\nInstalling Postfix\n");

        $postfixCheck = Process::run('dpkg -l | grep postfix')->output();

        if (str($postfixCheck)->contains(' postfix ')) {
            $this->line('Postfix is already installed.');

            return;
        }

        $update = Process::run('apt-get update');

        if (! $update->successful()) {
            throw new RuntimeException(
                '`apt-get update` failed; aborting setup before any configuration is changed: '
                .trim($update->output().' '.$update->errorOutput())
            );
        }

        $this->line($update->output());

        // debconf-set-selections stores the value verbatim (it is not a
        // shell), so the echoed line must carry no embedded quotes: they
        // would reach /etc/mailname and mydestination through the package's
        // postinst. The whole line is escaped as one shell argument instead.
        $mailname = escapeshellarg("postfix postfix/mailname string {$domain}");
        $mailerType = escapeshellarg('postfix postfix/main_mailer_type string Internet Site');

        $this->line(Process::run("echo {$mailname} | debconf-set-selections")->output());
        $this->line(Process::run("echo {$mailerType} | debconf-set-selections")->output());

        $install = Process::run('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix');

        if (! $install->successful()) {
            throw new RuntimeException(
                '`apt-get install -y postfix` failed; aborting setup before any configuration is changed: '
                .trim($install->output().' '.$install->errorOutput())
            );
        }

        $this->line($install->output());
    }

    /**
     * Configure the main.cf parameters.
     */
    private function configureMainConfigFile(string $domain): void
    {
        $this->info("\nConfiguring the Main config file.\n");

        $this->setMainParameter('myhostname', $domain);

        // Recipient restrictions
        $this->setMainParameter('smtpd_recipient_restrictions', self::RECIPIENT_RESTRICTIONS);

        $this->setMainParameter('local_recipient_maps', '');

        // Disable outbound delivery (receive-only)
        $this->setMainParameter('default_transport', 'error');
        $this->setMainParameter('relay_transport', 'error');

        // Message size limit
        $sizeLimit = config('receive_email.message-size-limit', 26214400);
        $this->setMainParameter('message_size_limit', "{$sizeLimit}");

        // HELO restrictions
        $this->setMainParameter('smtpd_helo_required', 'yes');
        $this->setMainParameter('smtpd_helo_restrictions', 'reject_invalid_helo_hostname, reject_non_fqdn_helo_hostname');

        // smtpd_sender_restrictions is owned by notifiable:sync-postfix,
        // invoked at the end of setup.

        // Disable VRFY
        $this->setMainParameter('disable_vrfy_command', 'yes');

        // Hide version from banner
        $this->setMainParameter('smtpd_banner', '$myhostname ESMTP');

        // Rate limiting
        $this->setMainParameter('smtpd_client_connection_rate_limit', '30');
        $this->setMainParameter('smtpd_client_message_rate_limit', '60');
        $this->setMainParameter('smtpd_client_recipient_rate_limit', '120');
        $this->setMainParameter('smtpd_error_sleep_time', '1s');
        $this->setMainParameter('smtpd_soft_error_limit', '5');
        $this->setMainParameter('smtpd_hard_error_limit', '10');

        // postscreen: drop clients that talk before the SMTP greeting
        $this->setMainParameter('postscreen_greet_action', 'enforce');

        // Data restrictions
        $this->setMainParameter('smtpd_data_restrictions', 'reject_unauth_pipelining');

        // Timeout hardening
        $this->setMainParameter('smtpd_timeout', '120s');

        // Queue lifetimes: the queue is the durability buffer for tempfailed
        // mail, so the retry window must outlive a multi-day incident.
        // bounce_queue_lifetime governs ALL mail with a null Envelope Sender
        // — including inbound DSNs accepted through the whitelist <>
        // exemption, not just locally generated bounces — so it must match
        // maximal_queue_lifetime: at 0, a single pipe tempfail would destroy
        // accepted mail after one delivery attempt.
        $this->setMainParameter('maximal_queue_lifetime', '5d');
        $this->setMainParameter('bounce_queue_lifetime', '5d');

        // TLS configuration
        $this->configureTLS();
    }

    /**
     * Configure TLS if cert and key are provided.
     */
    private function configureTLS(): void
    {
        /** @var string|null $tlsCert */
        $tlsCert = $this->option('tls-cert');

        /** @var string|null $tlsKey */
        $tlsKey = $this->option('tls-key');

        if ($tlsCert && $tlsKey) {
            if (! file_exists($tlsCert)) {
                throw new RuntimeException("TLS certificate file does not exist: {$tlsCert}");
            }

            if (! file_exists($tlsKey)) {
                throw new RuntimeException("TLS key file does not exist: {$tlsKey}");
            }

            $this->setMainParameter('smtpd_tls_cert_file', $tlsCert);
            $this->setMainParameter('smtpd_tls_key_file', $tlsKey);
            $this->setMainParameter('smtpd_tls_security_level', 'may');
            // ">=TLSv1.2" requires Postfix 3.6+; Ubuntu 24.04 ships 3.8.
            $this->setMainParameter('smtpd_tls_protocols', '>=TLSv1.2');
            $this->setMainParameter('smtpd_tls_loglevel', '1');
            $this->setMainParameter('smtp_tls_security_level', 'none');
        } else {
            $this->warn('TLS is not configured. Inbound SMTP connections will be unencrypted.');
            $this->warn('Use --tls-cert and --tls-key to enable TLS.');
        }
    }

    /**
     * Configure the master.cf services.
     */
    private function configureMasterConfigFile(string $user): void
    {
        $this->info("\nConfiguring the Master config file.\n");

        // postscreen topology: postscreen owns port 25 and drops botnet
        // zombies before they consume an smtpd process; legitimate clients
        // are handed to smtpd as a pass-through service, where the
        // content_filter keeps piping accepted mail into the notifiable
        // transport. tlsproxy keeps inbound STARTTLS working behind
        // postscreen; dnsblog is its DNS lookup helper.
        $this->setMasterService('smtp/inet', 'smtp inet n - - - 1 postscreen');
        $this->setMasterService('smtpd/pass', 'smtpd pass - - - - - smtpd -o content_filter=notifiable:dummy');
        $this->setMasterService('dnsblog/unix', 'dnsblog unix - - - - 0 dnsblog');
        $this->setMasterService('tlsproxy/unix', 'tlsproxy unix - - - - 0 tlsproxy');

        $command = $this->getReceiveEmailCommand();
        $concurrency = config('receive_email.pipe-concurrency', 4);

        $this->setMasterService('notifiable/unix', "notifiable unix - n n - {$concurrency} pipe flags=F user={$user} argv={$command}");
    }

    /**
     * Install and configure SPF verification.
     */
    private function configureSPF(): void
    {
        $this->info("\nConfiguring SPF verification\n");

        $this->installSpfPolicyDaemon();

        $this->setMainParameter('policy-spf_time_limit', '3600s');

        // Update smtpd_recipient_restrictions to include SPF check
        $this->setMainParameter(
            'smtpd_recipient_restrictions',
            self::RECIPIENT_RESTRICTIONS.', check_policy_service unix:private/policy-spf'
        );

        $this->setMasterService('policy-spf/unix', 'policy-spf unix - n n - 0 spawn user=policyd-spf argv=/usr/bin/policyd-spf');
    }

    /**
     * Ubuntu 24.04 packages the SPF policy daemon as
     * postfix-policyd-spf-python; upstream is migrating to spf-engine,
     * so later releases may only carry that name. Both ship the same
     * /usr/bin/policyd-spf entry point.
     */
    private function installSpfPolicyDaemon(): void
    {
        foreach (['postfix-policyd-spf-python', 'spf-engine'] as $package) {
            $install = Process::run("DEBIAN_FRONTEND=noninteractive apt-get install -y {$package}");

            if ($install->successful()) {
                $this->line($install->output());

                return;
            }
        }

        throw new RuntimeException(
            'Failed to install the SPF policy daemon: neither postfix-policyd-spf-python nor spf-engine could be '
            .'installed. SPF verification is what makes Envelope Sender filtering trustworthy; '
            .'pass --without-spf to set up without it.'
        );
    }

    /**
     * Setup installs packages and edits the Postfix configuration,
     * so it must run as the effective root user — abort before
     * anything is mutated.
     */
    private function assertRunningAsRoot(): void
    {
        $effectiveUserId = static::$effectiveUserId
            ?? (function_exists('posix_geteuid') ? posix_geteuid() : null);

        if ($effectiveUserId !== 0) {
            throw new RuntimeException(
                'This command must be run as root: it installs packages and edits '.PostfixDirectory::$path.'. '
                .'Re-run as `sudo php artisan notifiable:setup-postfix`.'
            );
        }
    }

    /**
     * The supported deployment platform is Ubuntu 24.04+. Other Debian-like
     * systems may work; --force skips the check for those.
     */
    private function assertSupportedOperatingSystem(): void
    {
        if ($this->option('force')) {
            $this->warn('Skipping the operating system check (--force).');

            return;
        }

        $osRelease = $this->readOsRelease();

        $id = $osRelease['ID'] ?? 'unknown';
        $versionId = $osRelease['VERSION_ID'] ?? '0';

        if ($id !== 'ubuntu' || version_compare($versionId, '24.04', '<')) {
            throw new RuntimeException(
                "Unsupported operating system: {$id} {$versionId}. This package supports Ubuntu 24.04+. "
                .'Pass --force to attempt setup on other Debian-like systems.'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function readOsRelease(): array
    {
        $path = static::$osReleasePath;

        if (! file_exists($path)) {
            throw new RuntimeException(
                "Cannot detect the operating system: {$path} does not exist. This package supports Ubuntu 24.04+. "
                .'Pass --force to attempt setup on other Debian-like systems.'
            );
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        $fields = [];

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^([A-Z_]+)="?([^"]*)"?$/', trim($line), $matches)) {
                $fields[$matches[1]] = $matches[2];
            }
        }

        return $fields;
    }

    /**
     * Verify the resulting configuration before reloading Postfix. A
     * pre-installed Postfix can carry a foreign mydestination that would
     * greet senders with "relay access denied" for the receiving domain.
     */
    private function verifyPostfixConfiguration(string $domain): void
    {
        $this->info("\nVerifying the Postfix configuration\n");

        $this->assertPostfixCheckPasses();

        $mydestination = trim(Process::run('postconf -x mydestination')->output());

        $destinations = preg_split('/[\s,]+/', trim((string) str($mydestination)->after('='))) ?: [];

        if (! in_array($domain, $destinations, true)) {
            throw new RuntimeException(
                "mydestination does not include {$domain} (got `{$mydestination}`), so Postfix would refuse its mail "
                ."with \"relay access denied\". Fix it with `postconf -e 'mydestination = {$domain}, localhost'` "
                .'and re-run this command.'
            );
        }
    }

    /**
     * Resolve the system user the pipe command runs as: the --user option,
     * then $SUDO_USER, then the current user. Postfix refuses to execute
     * pipe commands as a privileged user, so resolving to root must abort
     * setup before anything is mutated.
     */
    private function resolvePipeUser(): string
    {
        /** @var string|null $user */
        $user = $this->option('user');

        if ($user === null) {
            $sudoUser = getenv('SUDO_USER');

            $user = is_string($sudoUser) && $sudoUser !== ''
                ? $sudoUser
                : $this->currentUser();
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $user)) {
            throw new RuntimeException("Invalid system user: {$user}");
        }

        if ($user === 'root') {
            throw new RuntimeException(
                'The pipe command cannot run as root: Postfix refuses to execute pipe commands as a privileged user. '
                .'Re-run as `sudo php artisan notifiable:setup-postfix` from your deploy user, or pass --user=<deploy-user> explicitly.'
            );
        }

        return $user;
    }

    private function currentUser(): string
    {
        return function_exists('posix_geteuid')
            ? posix_getpwuid(posix_geteuid())['name'] ?? get_current_user()
            : get_current_user();
    }

    /**
     * The built-in sender lists are enforced as SMTP-time Envelope Sender
     * access maps; notifiable:sync-postfix owns those maps and the
     * smtpd_sender_restrictions line, and deploy hooks re-run it when the
     * lists change. Setup invokes it once and reloads Postfix itself after
     * the postflight checks.
     */
    private function syncSenderAccessMaps(): void
    {
        $this->info("\nSyncing the Envelope Sender access maps\n");

        if ($this->call(SyncPostfixCommand::class, ['--no-reload' => true]) !== Command::SUCCESS) {
            throw new RuntimeException('Failed to sync the Envelope Sender access maps.');
        }
    }

    private function reloadPostfix(): void
    {
        $this->info("\nReloading postfix\n");

        $reload = Process::run('systemctl reload postfix');

        if (! $reload->successful()) {
            throw new RuntimeException(
                'Failed to reload Postfix: the verified configuration is not active. '
                .trim($reload->output().' '.$reload->errorOutput())
            );
        }

        // The inner sync ran with --no-reload, leaving its reload pending;
        // this reload activates that configuration too.
        $this->clearPostfixReloadPending();

        $this->info('Postfix reloaded.');
    }

    private function getReceiveEmailCommand(): string
    {
        $basePath = base_path();

        // Zero-downtime deployments (Envoyer, Forge) use a releases/ directory
        // with a `current` symlink. Resolve to the `current` path so Postfix
        // always calls the active release after future deploys.
        $basePath = preg_replace('#/releases/[^/]+$#', '/current', $basePath);

        return "php {$basePath}/artisan notifiable:receive-email";
    }
}
