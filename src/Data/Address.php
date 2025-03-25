<?php

namespace Notifiable\ReceiveEmail\Data;

use InvalidArgumentException;

readonly class Address
{
    public function __construct(
        public string $address,
        public string $display
    ) {
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$address}");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        return new self(
            $data['address'],
            $data['display']
        );
    }

    /**
     * @param  array<mixed>  $data
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
