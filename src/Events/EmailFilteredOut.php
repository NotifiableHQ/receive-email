<?php

namespace Notifiable\ReceiveEmail\Events;

use Notifiable\ReceiveEmail\Data\Mail;

class EmailFilteredOut
{
    public function __construct(
        public string $filterClass,
        public Mail $mail,
    ) {}
}
