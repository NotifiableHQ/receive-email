<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;
use Notifiable\ReceiveEmail\Events\EmailFilteredOut;
use Notifiable\ReceiveEmail\Exceptions\InvalidFilterException;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeCommandException;
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

        $parsedMail = ParsedMail::source($emailStream);

        $this->applyFilters($parsedMail);

        $pipeCommand = app(config('receive_email.pipe-command'));

        if (! ($pipeCommand instanceof PipeCommandContract)) {
            throw new InvalidPipeCommandException;
        }

        $pipeCommand->handle($parsedMail);

        return self::EX_OK;
    }

    private function applyFilters(ParsedMailContract $parsedMail): void
    {
        /** @var string $filterClass */
        foreach (Config::array('receive_email.email-filters', []) as $filterClass) {
            $filter = app($filterClass);

            if (! ($filter instanceof EmailFilterContract)) {
                throw InvalidFilterException::filter($filterClass);
            }

            if ($filter->filter($parsedMail)) {
                continue;
            }

            event(new EmailFilteredOut($filterClass, $parsedMail->toMail()));

            exit(self::EX_NOHOST);
        }
    }
}
