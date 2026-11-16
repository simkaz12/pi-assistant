<?php

namespace App\Modules\System\Data;

use Carbon\CarbonImmutable;

final readonly class RunTick
{
    public function __construct(
        public CarbonImmutable $at,
        public bool $ok,
    ) {}

    /**
     * @param  array{at: string, ok: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(CarbonImmutable::parse($data['at']), $data['ok']);
    }

    /**
     * @param  list<array{at: string, ok: bool}>  $rows
     * @return list<self>
     */
    public static function collect(array $rows): array
    {
        return array_map(self::fromArray(...), $rows);
    }

    /**
     * @return array{at: string, ok: bool}
     */
    public function toArray(): array
    {
        return ['at' => $this->at->toIso8601String(), 'ok' => $this->ok];
    }
}
