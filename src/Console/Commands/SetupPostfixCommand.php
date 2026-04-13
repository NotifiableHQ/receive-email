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
        {--user= : The system user to run the pipe command as.}';

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
     * Configure the main.cf file:
     * - Set the domain name
     * - Add smtpd_recipient_restrictions
     * - Add local_recipient_maps
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

        $smtpdRecipientRestrictions = 'smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination';
        $localRecipientMaps = 'local_recipient_maps =';
        $this->upsertLine($mainConfig, $smtpdRecipientRestrictions);
        $this->upsertLine($mainConfig, $localRecipientMaps);
    }

    /**
     * Configure the master.cf file:
     * - Add SMTP daemon
     * - Add external delivery method
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

        $deliveryMethod = "notifiable unix - n n - - pipe flags=F user=$user argv={$command}";
        $this->upsertLine($masterConfig, $deliveryMethod);
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
