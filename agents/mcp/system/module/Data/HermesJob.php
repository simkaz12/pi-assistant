<?php

namespace App\Modules\System\Data;

use Carbon\CarbonImmutable;

final readonly class HermesJob
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $enabled,
        public ?string $state,
        public ?CarbonImmutable $pausedAt,
        public ?CarbonImmutable $nextRunAt,
        public ?CarbonImmutable $lastRunAt,
        public ?string $lastStatus,
        /** @var list<RunTick> */
        public array $recent = [],
    ) {}

    public function running(): bool
    {
        return $this->enabled && $this->state !== 'paused' && $this->pausedAt === null;
    }

    /**
     * @param  list<RunTick>  $recent
     */
    public function withRecent(array $recent): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->enabled,
            $this->state,
            $this->pausedAt,
            $this->nextRunAt,
            $this->lastRunAt,
            $this->lastStatus,
            $recent,
        );
    }
}
