<?php

namespace Notifiable\Filters;

use Illuminate\Support\Facades\Config;
use Notifiable\Contracts\EmailFilter;
use PhpMimeMailParser\Parser;

class SenderDomainWhitelistFilter implements EmailFilter
{
    public function filter(Parser $email): bool
    {
        $from = $email->getAddresses('from')[0]['address'];

        $domain = explode('@', $from)[1];

        return ! in_array($domain, Config::array('notifiable.sender-domain-whitelist', []));
    }
}
