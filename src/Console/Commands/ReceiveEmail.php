<?php

namespace Notifiable\Console\Commands;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Notifiable\Console\Contracts\EmailFilter;
use Notifiable\Events\EmailReceived;
use Notifiable\Models\ReceivedEmail;
use PhpMimeMailParser\Parser;
use Symfony\Component\Console\Command\Command;

class ReceiveEmail extends ConsoleCommand
{
    /** @var string */
    protected $signature = 'notifiable:receive-email';

    /** @var string */
    protected $description = 'Receive an email.';

    public function handle(): int
    {
        $emailStream = fopen('php://stdin', 'r');

        if ($emailStream === false) {
            $this->error('Could not open stream.');

            return Command::FAILURE;
        }

        $parser = (new Parser)->setStream($emailStream);

        foreach (Config::array('notifiable.email-filters', []) as $filterClass) {
            /** @var EmailFilter $filter */
            $filter = app($filterClass);

            if ($filter->filter($parser)) {
                // Track filtered mail?
                return Command::SUCCESS;
            }
        }

        /** @var ReceivedEmail $receivedEmail */
        $receivedEmail = ReceivedEmail::query()->create([
            'ulid' => (string) Str::ulid(),
        ]);

        Storage::put($receivedEmail->path(), $parser->getStream());

        event(new EmailReceived($receivedEmail));

        return Command::SUCCESS;
    }
}
