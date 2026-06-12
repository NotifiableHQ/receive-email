<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Contracts\PipeFilterContract;
use Notifiable\ReceiveEmail\Events\EmailRejected;
use Notifiable\ReceiveEmail\Exceptions\InvalidFilterException;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use Notifiable\ReceiveEmail\Filters\SenderAddressBlacklistFilter;
use Notifiable\ReceiveEmail\Filters\SenderAddressWhitelistFilter;
use Notifiable\ReceiveEmail\Filters\SenderDomainBlacklistFilter;
use Notifiable\ReceiveEmail\Filters\SenderDomainWhitelistFilter;
use Throwable;

class ApplyFilters implements PipeFilterContract
{
    /**
     * The built-in list filters are enforced at SMTP time as Envelope Sender
     * access maps (see notifiable:sync-postfix), so they are skipped here:
     * evaluating them pipe-time would re-apply the lists with the old Header
     * Sender semantics. Custom Pipe-time Filters are unaffected.
     */
    private const SMTP_TIME_FILTERS = [
        SenderAddressBlacklistFilter::class,
        SenderAddressWhitelistFilter::class,
        SenderDomainBlacklistFilter::class,
        SenderDomainWhitelistFilter::class,
    ];

    public function handle(ParsedMailContract $parsedMail): bool
    {
        /** @var string $filterClass */
        foreach (Config::array('receive_email.email-filters', []) as $filterClass) {
            if (in_array($filterClass, self::SMTP_TIME_FILTERS, true)) {
                continue;
            }

            $filter = app($filterClass);

            if (! ($filter instanceof EmailFilterContract)) {
                throw InvalidFilterException::filter($filterClass);
            }

            if ($filter->filter($parsedMail)) {
                continue;
            }

            try {
                $mail = $parsedMail->toMail();
            } catch (MalformedMailException) {
                // The filter rejected on the headers it could read; the
                // rest of the message may still be unparseable. The reject
                // verdict must win: letting this escape would convert the
                // Discard into kept Malformed Mail and silently drop
                // EmailRejected.
                $mail = null;
            }

            try {
                event(new EmailRejected($filterClass, $mail));
            } catch (Throwable $exception) {
                // A rejected message must be Discarded exactly once regardless
                // of listener health: letting a listener failure escape would
                // tempfail the pipe and have Postfix redeliver — and re-reject
                // — mail a filter deliberately rejected, until queue expiry.
                Log::error('An EmailRejected listener threw; the rejected message is still discarded.', [
                    'filter' => $filterClass,
                    'exception' => $exception,
                ]);
            }

            return false;
        }

        return true;
    }
}
