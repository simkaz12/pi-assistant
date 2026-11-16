<?php

namespace App\Modules\System\Data;

use Carbon\CarbonImmutable;

final readonly class PcPeriod
{
    public function __construct(
        public CarbonImmutable $from,
        public ?CarbonImmutable $to,
    ) {}

    /**
     * @return array{from: string, to: string|null}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toIso8601String(),
            'to' => $this->to?->toIso8601String(),
        ];
    }
}
