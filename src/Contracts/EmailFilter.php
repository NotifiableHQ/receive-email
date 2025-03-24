<?php

namespace Notifiable\ReceiveEmail\Contracts;

interface EmailFilter
{
    /**
     * Return TRUE for emails you want to accept.
     * Return FALSE for emails you want to reject.
     */
    public function filter(ParsedMail $parsedMail): bool;
}
