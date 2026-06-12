<?php

namespace Notifiable\ReceiveEmail\Contracts;

use Notifiable\ReceiveEmail\Data\Envelope;

/**
 * When Postfix receives an email, the email is streamed into the pipe command
 * together with its SMTP envelope.
 */
interface PipeCommandContract
{
    public function handle(ParsedMailContract $parsedMail, Envelope $envelope): void;
}
