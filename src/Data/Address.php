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
