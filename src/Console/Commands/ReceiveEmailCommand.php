<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;
use Notifiable\ReceiveEmail\Contracts\PipeFilterContract;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeCommandException;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeFilterException;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Throwable;

class ReceiveEmailCommand extends Command
{
    public const EX_OK = 0;

    public const EX_TEMPFAIL = 75;

    /** @var string */
    protected $signature = 'notifiable:receive-email';

    /** @var string */
    protected $description = 'Receive an email.';

    public function handle(): int
    {
        $emailStream = fopen('php://stdin', 'r');

        if ($emailStream === false) {
            Log::error('Could not open input stream. Exiting EX_TEMPFAIL so Postfix keeps the message queued.');

            return self::EX_TEMPFAIL;
        }

        try {
            return $this->receive($emailStream);
        } catch (MalformedMailException $exception) {
            // Retries cannot fix a broken message and a Receive-only server
            // never bounces, so the only permissible fate is to Discard it.
            Log::warning('Discarding malformed mail.', ['exception' => $exception]);

            return self::EX_OK;
        } catch (Throwable $exception) {
            Log::error('Failed to receive email. Exiting EX_TEMPFAIL so Postfix keeps the message queued.', ['exception' => $exception]);

            return self::EX_TEMPFAIL;
        } finally {
            if (is_resource($emailStream)) {
                fclose($emailStream);
            }
        }
    }

    /**
     * @param  resource  $emailStream
     */
    private function receive($emailStream): int
    {
        $parsedMail = ParsedMail::source($emailStream);

        $pipeFilter = app(config('receive_email.pipe-filter'));

        if (! ($pipeFilter instanceof PipeFilterContract)) {
            throw InvalidPipeFilterException::invalidClass(config('receive_email.pipe-filter'));
        }

        if ($pipeFilter->handle($parsedMail) === false) {
            // The message was already accepted at SMTP time, so a Pipe-time
            // Filter rejection must Discard: the filter has dispatched
            // EmailRejected, and a non-zero exit would ask Postfix for a
            // bounce this Receive-only server can never deliver.
            return self::EX_OK;
        }

        $pipeCommand = app(config('receive_email.pipe-command'));

        if (! ($pipeCommand instanceof PipeCommandContract)) {
            throw InvalidPipeCommandException::invalidClass(config('receive_email.pipe-command'));
        }

        $pipeCommand->handle($parsedMail);

        return self::EX_OK;
    }
}
