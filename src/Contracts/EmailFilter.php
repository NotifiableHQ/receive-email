<?php

namespace Notifiable\ReceiveEmail\Contracts;

use PhpMimeMailParser\Parser;

interface EmailFilter
{
    public function filter(Parser $email): bool;
}
