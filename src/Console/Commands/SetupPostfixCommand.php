<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Arr;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

class SetupPostfixCommand extends ConsoleCommand
{
    public const POSTFIX_DIR = '/etc/postfix';

    private const DOMAIN_PATTERN = '/^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/';

    /** @var string */
    protected $signature = 'notifiable:setup-postfix
        {domain : The domain where to receive emails from.}
        {--user= : The system user to run the pipe command as.}
        {--tls-cert= : Path to the TLS certificate file (PEM format).}
        {--tls-key= : Path to the TLS private key file (PEM format).}
        {--with-spf : Install and configure SPF verification via policyd-spf.}';

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
            $this->installPostfix($domain);
            $this->configureMainConfigFile($domain);
            $this->configureMasterConfigFile();

            if ($this->option('with-spf')) {
                $this->configureSPF();
            } else {
                $this->info("\nFor production use, consider --with-spf for SPF verification, and rspamd for DKIM/DMARC.");
            }

            $this->reloadPostfix();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function installPostfix(string $domain): void
    {
        $this->info("\nInstalling Postfix\n");

        $postfixCheck = shell_exec('dpkg -l | grep postfix');

        if (is_string($postfixCheck) && str($postfixCheck)->contains(' postfix ')) {
            $this->line('Postfix is already installed.');

            return;
        }

        $escapedDomain = escapeshellarg($domain);

        $this->line((string) shell_exec('apt-get update'));
        $this->line((string) shell_exec("debconf-set-selections <<< \"postfix postfix/mailname string {$escapedDomain}\""));
        $this->line((string) shell_exec("debconf-set-selections <<< \"postfix postfix/main_mailer_type string 'Internet Site'\""));
        $this->line((string) shell_exec('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix'));
    }

    /**
     * Configure the main.cf file.
     */
    private function configureMainConfigFile(string $domain): void
    {
        $this->info("\nConfiguring the Main config file.\n");

        $mainConfig = $this->getConfigPath('main.cf');

        $newHostname = "myhostname = $domain";
        $oldHostname = $this->editLine($mainConfig, '/^myhostname = (.*)$/m', $newHostname);

        if ($oldHostname === null) {
            throw new RuntimeException("'myhostname' is missing from {$mainConfig}.");
        }

        // Recipient restrictions
        $this->upsertOrEditLine($mainConfig, '/^smtpd_recipient_restrictions = (.*)$/m', 'smtpd_recipient_restrictions = permit_mynetworks, reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination');

        $this->upsertLine($mainConfig, 'local_recipient_maps =');

        // Disable outbound delivery (receive-only)
        $this->upsertLine($mainConfig, 'default_transport = error');
        $this->upsertLine($mainConfig, 'relay_transport = error');

        // Message size limit
        $this->upsertOrEditLine($mainConfig, '/^message_size_limit = (.*)$/m', 'message_size_limit = '.config('receive_email.message-size-limit', 26214400));

        // HELO restrictions
        $this->upsertLine($mainConfig, 'smtpd_helo_required = yes');
        $this->upsertLine($mainConfig, 'smtpd_helo_restrictions = reject_invalid_helo_hostname, reject_non_fqdn_helo_hostname');

        // Sender restrictions
        $this->upsertLine($mainConfig, 'smtpd_sender_restrictions = reject_non_fqdn_sender, reject_unknown_sender_domain');

        // Disable VRFY
        $this->upsertLine($mainConfig, 'disable_vrfy_command = yes');

        // Hide version from banner
        $this->upsertLine($mainConfig, 'smtpd_banner = $myhostname ESMTP');

        // Rate limiting
        $this->upsertLine($mainConfig, 'smtpd_client_connection_rate_limit = 30');
        $this->upsertLine($mainConfig, 'smtpd_client_message_rate_limit = 60');
        $this->upsertLine($mainConfig, 'smtpd_client_recipient_rate_limit = 120');
        $this->upsertLine($mainConfig, 'smtpd_error_sleep_time = 1s');
        $this->upsertLine($mainConfig, 'smtpd_soft_error_limit = 5');
        $this->upsertLine($mainConfig, 'smtpd_hard_error_limit = 10');

        // Data restrictions
        $this->upsertLine($mainConfig, 'smtpd_data_restrictions = reject_unauth_pipelining');

        // Timeout hardening
        $this->upsertLine($mainConfig, 'smtpd_timeout = 120s');

        // Queue lifetimes
        $this->upsertLine($mainConfig, 'maximal_queue_lifetime = 1d');
        $this->upsertLine($mainConfig, 'bounce_queue_lifetime = 1d');

        // TLS configuration
        $this->configureTLS($mainConfig);
    }

    /**
     * Configure TLS if cert and key are provided.
     */
    private function configureTLS(string $mainConfig): void
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

            $this->upsertOrEditLine($mainConfig, '/^smtpd_tls_cert_file = (.*)$/m', "smtpd_tls_cert_file = {$tlsCert}");
            $this->upsertOrEditLine($mainConfig, '/^smtpd_tls_key_file = (.*)$/m', "smtpd_tls_key_file = {$tlsKey}");
            $this->upsertLine($mainConfig, 'smtpd_tls_security_level = may');
            $this->upsertLine($mainConfig, 'smtpd_tls_protocols = !SSLv2, !SSLv3, !TLSv1, !TLSv1.1');
            $this->upsertLine($mainConfig, 'smtpd_tls_loglevel = 1');
            $this->upsertLine($mainConfig, 'smtp_tls_security_level = none');
        } else {
            $this->warn('TLS is not configured. Inbound SMTP connections will be unencrypted.');
            $this->warn('Use --tls-cert and --tls-key to enable TLS.');
        }
    }

    /**
     * Configure the master.cf file.
     */
    private function configureMasterConfigFile(): void
    {
        $this->info("\nConfiguring the Master config file.\n");

        $masterConfig = $this->getConfigPath('master.cf');

        $newSmtpDaemon = 'smtp inet n - - - - smtpd -o content_filter=notifiable:dummy';
        $oldSmtpDaemon = $this->editLine($masterConfig, '/^smtp(\s+)inet(.*)$/m', $newSmtpDaemon);

        if ($oldSmtpDaemon === null) {
            throw new RuntimeException("'smtp inet' is missing from {$masterConfig}.");
        }

        $user = $this->resolveUser();
        $command = $this->getReceiveEmailCommand();
        $concurrency = config('receive_email.pipe-concurrency', 4);

        $deliveryMethod = "notifiable unix - n n - {$concurrency} pipe flags=F user=$user argv={$command}";
        $this->upsertOrEditLine($masterConfig, '/^notifiable(.*)$/m', $deliveryMethod);
    }

    /**
     * Install and configure SPF verification.
     */
    private function configureSPF(): void
    {
        $this->info("\nConfiguring SPF verification\n");

        $this->line((string) shell_exec('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python'));

        $mainConfig = $this->getConfigPath('main.cf');
        $this->upsertLine($mainConfig, 'policy-spf_time_limit = 3600s');

        // Update smtpd_recipient_restrictions to include SPF check
        $smtpdRecipientRestrictions = 'smtpd_recipient_restrictions = permit_mynetworks, reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination, check_policy_service unix:private/policy-spf';
        $this->editLine($mainConfig, '/^smtpd_recipient_restrictions = (.*)$/m', $smtpdRecipientRestrictions);

        $masterConfig = $this->getConfigPath('master.cf');
        $this->upsertLine($masterConfig, 'policy-spf unix -  n  n  -  0  spawn user=policyd-spf argv=/usr/bin/policyd-spf');
    }

    private function resolveUser(): string
    {
        /** @var string|null $user */
        $user = $this->option('user');

        if ($user === null) {
            $user = function_exists('posix_geteuid')
                ? posix_getpwuid(posix_geteuid())['name'] ?? get_current_user()
                : get_current_user();
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $user)) {
            throw new RuntimeException("Invalid system user: {$user}");
        }

        return $user;
    }

    private function reloadPostfix(): void
    {
        $this->info("\nReloading postfix\n");
        $this->line((string) shell_exec('systemctl reload postfix'));
    }

    private function getConfigPath(string $config): string
    {
        $path = self::POSTFIX_DIR.'/'.$config;

        if (! file_exists($path)) {
            throw new RuntimeException("The {$path} file does not exist!");
        }

        return $path;
    }

    private function editLine(string $filePath, string $regex, string $newLine): ?string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$filePath}");
        }

        $matches = [];
        if (! preg_match($regex, $content, $matches)) {
            return null;
        }

        /** @var string $originalLine */
        $originalLine = Arr::first($matches);

        file_put_contents($filePath, str_replace($originalLine, $newLine, $content));

        $this->line("--- Editing {$filePath} ---");
        $this->line("From: {$originalLine}");
        $this->line("To:  {$newLine}");

        return $originalLine;
    }

    private function upsertOrEditLine(string $filePath, string $regex, string $newLine): void
    {
        if ($this->editLine($filePath, $regex, $newLine) === null) {
            $this->upsertLine($filePath, $newLine);
        }
    }

    private function upsertLine(string $filePath, string $line): void
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$filePath}");
        }

        if (str($content)->contains($line)) {
            return;
        }

        file_put_contents($filePath, "\n$line\n", FILE_APPEND);
        $this->line("Append to {$filePath} : {$line}");
    }

    private function getReceiveEmailCommand(): string
    {
        $artisan = base_path('artisan');

        return "php $artisan notifiable:receive-email";
    }
}
