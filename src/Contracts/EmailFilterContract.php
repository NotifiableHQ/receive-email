<?php

namespace Notifiable\ReceiveEmail\Contracts;

interface EmailFilterContract
{
    /**
     * Return TRUE for emails you want to accept.
     * Return FALSE for emails you want to reject.
     */
    public function filter(ParsedMailContract $parsedMail): bool;
}
