<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\EmailFilter;
use Notifiable\ReceiveEmail\Contracts\ParsedMail;
use Notifiable\ReceiveEmail\Contracts\PipeCommand;
use Notifiable\ReceiveEmail\Events\EmailFilteredOut;
use Notifiable\ReceiveEmail\Exceptions\InvalidFilterException;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeCommandException;
use Notifiable\ReceiveEmail\ParserParsedMail;
use PhpMimeMailParser\Parser;

class ReceiveEmail extends Command
{
    public const EX_OK = 0;

    public const EX_NOINPUT = 66;

    public const EX_NOHOST = 68;

    /** @var string */
    protected $signature = 'notifiable:receive-email';

    /** @var string */
    protected $description = 'Receive an email.';

    protected Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser;

        parent::__construct();
    }

    public function handle(): int
    {
        $emailStream = fopen('php://stdin', 'r');

        if ($emailStream === false) {
            $this->error('Could not open stream.');

            return self::EX_NOINPUT;
        }

        $parser = (new Parser)->setStream($emailStream);

        $parsedMail = new ParserParsedMail($parser);

        $this->applyFilters($parsedMail);

        $pipeCommand = app(config('notifiable.pipe-command'));

        if (! ($pipeCommand instanceof PipeCommand)) {
            throw new InvalidPipeCommandException;
        }

        $pipeCommand->handle($parsedMail);

        return self::EX_OK;
    }

    private function applyFilters(ParsedMail $parsedMail): void
    {
        /** @var string $filterClass */
        foreach (Config::array('notifiable.email-filters', []) as $filterClass) {
            $filter = app($filterClass);

            if (! ($filter instanceof EmailFilter)) {
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
