<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class MalformedEmailException extends Exception
{
    public static function missingHeader(string $key): MalformedEmailException
    {
        return new MalformedEmailException("[{$key}] header is missing.");
    }

    public static function missingFromAddress(): MalformedEmailException
    {
        return new MalformedEmailException('Missing from email address.');
    }

    public static function missingRecipient(): MalformedEmailException
    {
        return new MalformedEmailException('Missing recipient email address.');
    }
}
