<?php

namespace Notifiable\ReceiveEmail\Contracts;

/**
 * When Postfix receives an email, the email is streamed into the pipe command.
 */
interface PipeCommandContract
{
    public function handle(ParsedMailContract $parsedMail): void;
}
