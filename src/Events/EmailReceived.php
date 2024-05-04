<?php

namespace Notifiable\ReceiveEmail\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Notifiable\ReceiveEmail\Models\ReceivedEmail;

class EmailReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ReceivedEmail $email
    ) {
    }
}
