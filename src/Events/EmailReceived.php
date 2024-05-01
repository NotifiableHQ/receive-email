<?php

namespace Notifiable\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Notifiable\Models\ReceivedEmail;

class EmailReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ReceivedEmail $email
    ) {
    }
}
