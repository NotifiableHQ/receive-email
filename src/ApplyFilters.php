<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Contracts\PipeFilterContract;
use Notifiable\ReceiveEmail\Events\EmailFilteredOut;
use Notifiable\ReceiveEmail\Exceptions\InvalidFilterException;

class ApplyFilters implements PipeFilterContract
{
    public function handle(ParsedMailContract $parsedMail): bool
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

            return false;
        }

        return true;
    }
}
