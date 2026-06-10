<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;
use Notifiable\ReceiveEmail\Contracts\PipeFilterContract;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeCommandException;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeFilterException;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use RuntimeException;
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
        $emailStream = $this->inputStream();

        if ($emailStream === false) {
            Log::error('Could not open input stream. Exiting EX_TEMPFAIL so Postfix keeps the message queued.');

            return self::EX_TEMPFAIL;
        }

        $bufferedStream = null;

        try {
            $bufferedStream = $this->bufferInput($emailStream);

            if ($bufferedStream === null) {
                Log::error('Refusing input larger than the configured message-size-limit. Exiting EX_TEMPFAIL so Postfix keeps the message queued.');

                return self::EX_TEMPFAIL;
            }

            return $this->receive($bufferedStream);
        } catch (MalformedMailException $exception) {
            // Retries cannot fix a broken message and a Receive-only server
            // never bounces, so the only permissible fate is to Discard it.
            Log::warning('Discarding malformed mail.', ['exception' => $exception]);

            return self::EX_OK;
        } catch (Throwable $exception) {
            // Tempfail preserves mail: Postfix keeps it queued, so it delivers once the operator fixes the failing condition.
            Log::error('Failed to receive email. Exiting EX_TEMPFAIL so Postfix keeps the message queued.', ['exception' => $exception]);

            return self::EX_TEMPFAIL;
        } finally {
            if (is_resource($emailStream)) {
                fclose($emailStream);
            }

            if (is_resource($bufferedStream)) {
                fclose($bufferedStream);
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

    /**
     * Copy the input into a seekable temp buffer, reading at most one byte
     * past the configured message-size-limit. Postfix already enforces the
     * limit at SMTP time; this guards against a hand-edited main.cf or a
     * refactor dropping the setting, without pulling unbounded data into
     * PHP memory.
     *
     * @param  resource  $input
     * @return resource|null Null when the input exceeds the limit.
     */
    private function bufferInput($input)
    {
        $limit = Config::integer('receive_email.message-size-limit', 26214400);

        $buffer = fopen('php://temp', 'r+');

        if ($buffer === false) {
            throw new RuntimeException('Could not open temporary buffer stream.');
        }

        $copied = stream_copy_to_stream($input, $buffer, $limit + 1);

        if ($copied === false) {
            fclose($buffer);

            throw new RuntimeException('Could not read the input stream.');
        }

        if ($copied > $limit) {
            fclose($buffer);

            return null;
        }

        rewind($buffer);

        return $buffer;
    }

    /**
     * @return resource|false
     */
    protected function inputStream()
    {
        return fopen('php://stdin', 'r');
    }
}
