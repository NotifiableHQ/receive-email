<?php

namespace Notifiable\Console\Commands;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Command\Command;

class ConfigurePostfix extends ConsoleCommand
{
    public const POSTFIX_DIR = '/etc/postfix';

    /** @var string */
    protected $signature = 'notifiable:configure-postfix {domain : The domain where to receive emails from.}';

    /** @var string */
    protected $description = 'Configure Postfix to receive emails.';

    public function handle(): int
    {
        $domain = $this->argument('domain');

        $this->configureMainConfigFile($domain);
        $this->configureMasterConfigFile();
        $this->reloadPostfix();

        return Command::SUCCESS;
    }

    private function getConfigPath(string $config): ?string
    {
        $path = self::POSTFIX_DIR.'/'.$config;

        if (! file_exists($path)) {
            $this->error('The config file does not exists!');

            return null;
        }

        return $path;
    }

    private function getReceiveEmailCommand(): string
    {
        $artisan = base_path('artisan');

        return "php $artisan notifiable:receive-email";
    }

    /**
     * Configure the main.cf file:
     * - Set the domain name
     * - Add smtpd_recipient_restrictions
     * - Add local_recipient_maps
     */
    private function configureMainConfigFile($newDomain): void
    {
        $mainConfig = $this->getConfigPath('main.cf');

        if ($mainConfig === null) {
            return;
        }

        $newHostname = "myhostname = $newDomain";
        $oldHostname = $this->editLine($mainConfig, '/^myhostname = (.*)$/m', $newHostname);

        if ($oldHostname === null) {
            $this->error("'myhostname' is missing from {$mainConfig}.");

            return;
        }

        $smtpdRecipientRestrictions = 'smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination';
        $localRecipientMaps = 'local_recipient_maps =';
        file_put_contents($mainConfig, "\n$smtpdRecipientRestrictions\n", FILE_APPEND);
        file_put_contents($mainConfig, "\n$localRecipientMaps\n", FILE_APPEND);

        $this->line("Append to {$mainConfig} : {$smtpdRecipientRestrictions}");
        $this->line("Append to {$mainConfig} : {$localRecipientMaps}");
    }

    /**
     * Configure the master.cf file:
     * - Instantiate content filter
     * - Add external delivery method
     */
    private function configureMasterConfigFile(): void
    {
        $masterConfig = $this->getConfigPath('master.cf');

        if ($masterConfig === null) {
            return;
        }

        $newContentFilter = 'smtp inet n - - - - smtpd -o content_filter=filter:dummy';
        $oldContentFilter = $this->editLine($masterConfig, '/^smtp(\s+)inet(.*)$/m', $newContentFilter);

        if (! $oldContentFilter) {
            $this->error("'smtp inet' is missing from {$masterConfig}.");

            return;
        }

        $user = get_current_user();
        $command = $this->getReceiveEmailCommand();

        $deliveryMethod = "notifiable unix - n n - - pipe flags=F user=$user argv={$command}";
        file_put_contents($masterConfig, "\n$deliveryMethod\n", FILE_APPEND);

        $this->line("Append to {$masterConfig} : {$deliveryMethod}");
    }

    private function reloadPostfix(): void
    {
        $this->info("\nReloading postfix\n");
        shell_exec('systemctl reload postfix');
    }

    private function editLine(string $filePath, string $regex, string $newLine): ?string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            $this->error("Failed to read file: {$filePath}");

            return null;
        }

        $matches = [];
        if (! preg_match($regex, $content, $matches)) {
            return null;
        }

        /** @var string $previousLine */
        $previousLine = Arr::first($matches);

        file_put_contents($filePath, str_replace($previousLine, $newLine, $content));

        $this->line("Editing {$filePath}...");
        $this->line("From:\t {$filePath}");
        $this->line("To:\t  {$filePath}");

        return $previousLine;
    }
}
