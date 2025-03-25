<?php

namespace Notifiable\ReceiveEmail\Filters;

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\EmailFilter;
use Notifiable\ReceiveEmail\Contracts\ParsedMail;

class SenderAddressWhitelistFilter implements EmailFilter
{
    public function filter(ParsedMail $parsedMail): bool
    {
        return in_array($parsedMail->from()->address, Config::array('receive_email.sender-address-whitelist', []));
    }
}
