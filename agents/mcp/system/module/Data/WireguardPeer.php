<?php

namespace App\Modules\System\Data;

use Carbon\CarbonImmutable;

final readonly class WireguardPeer
{
    public function __construct(
        public string $name,
        public string $address,
        public bool $enabled,
        public ?CarbonImmutable $lastHandshakeAt,
    ) {}

    /**
     * @param  array{name: string, address: string, enabled: bool, last_handshake_at: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['address'],
            $data['enabled'],
            $data['last_handshake_at'] === null ? null : CarbonImmutable::parse($data['last_handshake_at']),
        );
    }

    /**
     * @return array{name: string, address: string, enabled: bool, last_handshake_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'enabled' => $this->enabled,
            'last_handshake_at' => $this->lastHandshakeAt?->toIso8601String(),
        ];
    }
}
