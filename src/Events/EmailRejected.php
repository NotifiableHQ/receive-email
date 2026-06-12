<?php

namespace Notifiable\ReceiveEmail\Events;

use Notifiable\ReceiveEmail\Data\Mail;

class EmailRejected
{
    public function __construct(
        public string $filterClass,
        /**
         * Null when the rejected message could not be fully parsed: the
         * filter rejected on the headers it could read, but the complete
         * Mail payload was not buildable. The message is Discarded either
         * way — the rejection verdict always wins.
         */
        public ?Mail $mail,
    ) {}
}
