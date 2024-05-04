<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Notifiable\ReceiveEmail\Contracts\EmailFilter;
use Notifiable\ReceiveEmail\Events\EmailFiltered;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Models\ReceivedEmail;
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

        /** @var string $filterClass */
        foreach (Config::array('notifiable.email-filters', []) as $filterClass) {
            /** @var EmailFilter $filter */
            $filter = app($filterClass);

            if ($filter->filter($parser)) {
                event(new EmailFiltered(
                    filterClass: $filterClass,
                    messageId: (string) $parser->getHeader('message-id'),
                    fromAddress: (string) $parser->getAddresses('from')[0]['address'],
                    subject: (string) $parser->getHeader('subject')
                ));

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
