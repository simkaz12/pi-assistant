<?php

namespace App\Modules\System\Data;

final readonly class DynamicDnsStatus
{
    public function __construct(
        public string $resolvedIp,
        public string $publicIp,
    ) {}

    public function matches(): bool
    {
        return $this->resolvedIp === $this->publicIp;
    }

    /**
     * @param  array{resolved_ip: string, public_ip: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['resolved_ip'], $data['public_ip']);
    }

    /**
     * @return array{resolved_ip: string, public_ip: string}
     */
    public function toArray(): array
    {
        return [
            'resolved_ip' => $this->resolvedIp,
            'public_ip' => $this->publicIp,
        ];
    }
}
