<?php

namespace Notifiable\ReceiveEmail\MailLog;

readonly class MailLogPosition
{
    public function __construct(
        public ?int $inode,
        public int $offset,
    ) {}
}
