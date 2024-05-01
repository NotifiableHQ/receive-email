<?php

namespace Notifiable\Console\Commands;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Notifiable\Events\EmailReceived;
use Notifiable\Models\ReceivedEmail;
use Symfony\Component\Console\Command\Command;

class ReceiveEmail extends ConsoleCommand
{
    /** @var string */
    protected $signature = 'notifiable:receive-email';

    /** @var string */
    protected $description = 'Receive an email.';

    public function handle(): int
    {
        $stream = fopen('php://stdin', 'r');

        if ($stream === false) {
            $this->error('Could not open stream.');

            return Command::FAILURE;
        }

        $streamedEmail = '';
        while (! feof($stream)) {
            $streamedEmail .= fread($stream, 1024);
        }
        fclose($stream);

        /** @var ReceivedEmail $receivedEmail */
        $receivedEmail = ReceivedEmail::query()->create([
            'ulid' => (string) Str::ulid(),
        ]);

        Storage::put($receivedEmail->path(), $streamedEmail);

        event(new EmailReceived($receivedEmail));

        return Command::SUCCESS;
    }
}
