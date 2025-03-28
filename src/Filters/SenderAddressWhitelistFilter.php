<?php

namespace Notifiable\ReceiveEmail\Filters;

use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;

class SenderAddressWhitelistFilter implements EmailFilterContract
{
    public function filter(ParsedMailContract $parsedMail): bool
    {
        return in_array(
            mb_strtolower($parsedMail->sender()->address),
            array_map('mb_strtolower',
                Config::array('receive_email.sender-address-whitelist', [])
            )
        );
    }
}
