<?php

namespace Notifiable\ReceiveEmail\Contracts;

/**
 * When Postfix receives an email, the email is filtered before it gets passed into the pipe command.
 */
interface PipeFilterContract
{
    public function handle(ParsedMailContract $parsedMail): bool;
}
