<?php

namespace Notifiable\ReceiveEmail\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmailFiltered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $filterClass,
        public string $messageId,
        public string $fromAddress,
        public string $subject,
    ) {
    }
}
