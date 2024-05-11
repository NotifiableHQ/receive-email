<?php

namespace Notifiable\ReceiveEmail\Events;

use Illuminate\Queue\SerializesModels;
use Notifiable\ReceiveEmail\Models\ReceivedEmail;

class EmailReceived
{
    use SerializesModels;

    public function __construct(
        public ReceivedEmail $email
    ) {
    }
}
