<?php

namespace Notifiable\ReceiveEmail\Events;

class EmailFiltered
{
    /** @param  array<string> $toAddresses */
    public function __construct(
        public string $filterClass,
        public string $messageId,
        public string $fromAddress,
        public array $toAddresses,
        public string $subject,
    ) {
    }
}
