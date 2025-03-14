<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Notifiable\ReceiveEmail\Contracts\EmailFilter;
use Notifiable\ReceiveEmail\Events\EmailFiltered;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Models\ReceivedEmail;
use PhpMimeMailParser\Parser;

use function Notifiable\ReceiveEmail\storage;

class ReceiveEmail extends Command
{
    public const EX_OK = 0;

    public const EX_NOINPUT = 66;

    public const EX_NOHOST = 68;

    /** @var string */
    protected $signature = 'notifiable:receive-email';

    /** @var string */
    protected $description = 'Receive an email.';

    public function handle(): int
    {
        $emailStream = fopen('php://stdin', 'r');

        if ($emailStream === false) {
            $this->error('Could not open stream.');

            return self::EX_NOINPUT;
        }

        $parser = (new Parser)->setStream($emailStream);

        $messageId = Str::remove(['<', '>'], (string) $parser->getHeader('message-id'));

        $this->applyFilters($parser, $messageId);

        $this->storeEmail($parser, $messageId);

        return self::EX_OK;
    }

    private function applyFilters(Parser $parser, string $messageId): void
    {
        /** @var array<string> $toAddresses */
        $toAddresses = data_get($parser->getAddresses('to'), '*.address', []);

        /** @var string $filterClass */
        foreach (Config::array('notifiable.email-filters', []) as $filterClass) {
            /** @var EmailFilter $filter */
            $filter = app($filterClass);

            if ($filter->filter($parser)) {
                event(new EmailFiltered(
                    filterClass: $filterClass,
                    messageId: $messageId,
                    fromAddress: (string) $parser->getAddresses('from')[0]['address'],
                    toAddresses: $toAddresses,
                    subject: (string) $parser->getHeader('subject')
                ));

                exit(self::EX_NOHOST);
            }
        }
    }

    private function storeEmail(Parser $parser, string $messageId): void
    {
        /** @var ReceivedEmail $receivedEmail */
        $receivedEmail = ReceivedEmail::query()->create([
            'message_id' => $messageId,
            'mailbox' => $parser->getAddresses('to')[0]['address'],
        ]);

        storage()->put($receivedEmail->path(), $parser->getStream());

        event(new EmailReceived($receivedEmail));
    }
}
