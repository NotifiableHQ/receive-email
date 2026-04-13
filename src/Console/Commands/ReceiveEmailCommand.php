<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command;
use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;
use Notifiable\ReceiveEmail\Contracts\PipeFilterContract;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeCommandException;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeFilterException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;

class ReceiveEmailCommand extends Command
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

        try {
            $parsedMail = ParsedMail::source($emailStream);

            $pipeFilter = app(config('receive_email.pipe-filter'));

            if (! ($pipeFilter instanceof PipeFilterContract)) {
                throw InvalidPipeFilterException::invalidClass(config('receive_email.pipe-filter'));
            }

            if ($pipeFilter->handle($parsedMail) === false) {
                return self::EX_NOHOST;
            }

            $pipeCommand = app(config('receive_email.pipe-command'));

            if (! ($pipeCommand instanceof PipeCommandContract)) {
                throw InvalidPipeCommandException::invalidClass(config('receive_email.pipe-command'));
            }

            $pipeCommand->handle($parsedMail);

            return self::EX_OK;
        } finally {
            if (is_resource($emailStream)) {
                fclose($emailStream);
            }
        }
    }
}
