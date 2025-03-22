<?php

namespace Notifiable\ReceiveEmail\Events;

class EmailFilteredOut
{
    /** @param  array<string> $toAddresses */
    public function __construct(
        public string $filterClass,
        public string $messageId,
        public string $subject,
        public string $fromAddress,
        public array $toAddresses,
    ) {}
}
