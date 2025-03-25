<?php

namespace Notifiable\ReceiveEmail\Data;

use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;

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
            throw MalformedMailException::missingRecipient();
        }
    }

    /**
     * @return string[]
     */
    public function toAddresses(): array
    {
        return $this->getAddresses($this->to);
    }

    /**
     * @return string[]
     */
    public function ccAddresses(): array
    {
        return $this->getAddresses($this->cc);
    }

    /**
     * @return string[]
     */
    public function bccAddresses(): array
    {
        return $this->getAddresses($this->bcc);
    }

    /**
     * @return string[]
     */
    public function allAddresses(): array
    {
        return $this->getAddresses(array_merge($this->to, $this->cc, $this->bcc));
    }

    /**
     * @param  Address[]  $addresses
     * @return string[]
     */
    private function getAddresses(array $addresses): array
    {
        $emails = [];

        foreach ($addresses as $address) {
            $emails[] = $address->address;
        }

        return $emails;
    }
}
