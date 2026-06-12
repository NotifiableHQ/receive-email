<?php

namespace Notifiable\ReceiveEmail\Events;

use Notifiable\ReceiveEmail\Data\SmtpRejection;

class SmtpRejectionObserved
{
    public function __construct(
        public SmtpRejection $rejection,
    ) {}
}
