<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Contracts\PipeFilterContract;
use Notifiable\ReceiveEmail\Events\EmailRejected;
use Notifiable\ReceiveEmail\Exceptions\InvalidFilterException;
use Notifiable\ReceiveEmail\Filters\SenderAddressBlacklistFilter;
use Notifiable\ReceiveEmail\Filters\SenderAddressWhitelistFilter;
use Notifiable\ReceiveEmail\Filters\SenderDomainBlacklistFilter;
use Notifiable\ReceiveEmail\Filters\SenderDomainWhitelistFilter;

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

            event(new EmailRejected($filterClass, $parsedMail->toMail()));

            return false;
        }

        return true;
    }
}
