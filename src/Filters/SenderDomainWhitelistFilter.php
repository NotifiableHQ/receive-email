<?php

namespace Notifiable\ReceiveEmail\Filters;

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\EmailFilter;
use Notifiable\ReceiveEmail\Contracts\ParsedMail;

class SenderDomainWhitelistFilter implements EmailFilter
{
    public function filter(ParsedMail $parsedMail): bool
    {
        return in_array($parsedMail->from()->domain(), Config::array('receive_email.sender-domain-whitelist', []));
    }
}
