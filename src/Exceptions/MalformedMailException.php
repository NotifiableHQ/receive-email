<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class MalformedMailException extends Exception
{
    public static function missingHeader(string $key): MalformedMailException
    {
        return new MalformedMailException("[{$key}] header is missing.");
    }

    public static function missingFromAddress(): MalformedMailException
    {
        return new MalformedMailException('Missing from email address.');
    }

    public static function missingRecipient(): MalformedMailException
    {
        return new MalformedMailException('Missing recipient email address.');
    }
}
