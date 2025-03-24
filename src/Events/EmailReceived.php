<?php

namespace Notifiable\ReceiveEmail\Events;

use Illuminate\Queue\SerializesModels;
use Notifiable\ReceiveEmail\Models\Email;

class EmailReceived
{
    use SerializesModels;

    public function __construct(
        public Email $email
    ) {}
}
