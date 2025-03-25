<?php

namespace Notifiable\ReceiveEmail\Contracts;

/**
 * When Postfix receives an email, the email is streamed into the pipe command.
 */
interface PipeCommand
{
    public function handle(ParsedMail $parsedMail): void;
}
