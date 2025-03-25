<?php

namespace Notifiable\ReceiveEmail\Data;

use Carbon\CarbonImmutable;

readonly class Mail
{
    public function __construct(
        public string $messageId,
        public CarbonImmutable $date,
        public Address $sender,
        public Recipients $recipients,
        public ?string $subject = null,
        public ?string $text = null,
        public ?string $html = null,
    ) {}
}
