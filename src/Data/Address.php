<?php

namespace Notifiable\ReceiveEmail\Data;

readonly class Address
{
    public function __construct(
        public string $address,
        public string $display
    ) {}

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
}
