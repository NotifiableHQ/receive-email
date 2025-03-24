<?php

namespace Notifiable\ReceiveEmail\Data;

readonly class Address
{
    public function __construct(
        public string $address,
        public string $display
    ) {
        // Add address validation?
    }

    public static function from(array $data): self
    {
        return new self(
            $data['address'],
            $data['display']
        );
    }

    /**
     * @return Address[]
     */
    public static function fromMany(array $data): array
    {
        $addresses = [];

        foreach ($data as $address) {
            $addresses[] = self::from($address);
        }

        return $addresses;
    }

    public function domain(): string
    {
        return explode('@', $this->address)[1];
    }
}
