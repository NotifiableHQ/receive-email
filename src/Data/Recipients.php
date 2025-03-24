<?php

namespace Notifiable\ReceiveEmail\Data;

use Notifiable\ReceiveEmail\Exceptions\MalformedEmailException;

readonly class Recipients
{
    /**
     * @param  Address[]  $to
     * @param  Address[]  $cc
     * @param  Address[]  $bcc
     */
    public function __construct(
        public array $to,
        public array $cc,
        public array $bcc,
    ) {
        if ($to === [] && $cc === [] && $bcc === []) {
            throw MalformedEmailException::missingRecipient();
        }
    }
}
