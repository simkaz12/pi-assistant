<?php

namespace App\Modules\System\Data;

use Carbon\CarbonImmutable;

final readonly class ScheduleStatus
{
    public function __construct(
        public string $name,
        public int $intervalSeconds,
        public CarbonImmutable $firstSeenAt,
        public CarbonImmutable $nextDueAt,
        public ?CarbonImmutable $lastStartedAt,
        public ?CarbonImmutable $lastFinishedAt,
        public ?int $lastExitCode,
        public bool $lastFailed,
        /** @var list<RunTick> */
        public array $recent = [],
    ) {}

    public function graceSeconds(): int
    {
        return max(2 * $this->intervalSeconds, 600);
    }

    public function missedReference(?CarbonImmutable $switchedOnAt = null): CarbonImmutable
    {
        $reference = $this->lastFinishedAt ?? $this->firstSeenAt;

        return $switchedOnAt !== null && $switchedOnAt->greaterThan($reference) ? $switchedOnAt : $reference;
    }

    public function isMissed(CarbonImmutable $now, ?CarbonImmutable $switchedOnAt = null): bool
    {
        return $this->missedReference($switchedOnAt)->diffInSeconds($now, false) > $this->graceSeconds();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['interval_seconds'],
            CarbonImmutable::parse($data['first_seen_at']),
            CarbonImmutable::parse($data['next_due_at']),
            self::instant($data['last_started_at']),
            self::instant($data['last_finished_at']),
            $data['last_exit_code'],
            $data['last_failed'],
            RunTick::collect($data['recent'] ?? []),
        );
    }

    private static function instant(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'interval_seconds' => $this->intervalSeconds,
            'first_seen_at' => $this->firstSeenAt->toIso8601String(),
            'next_due_at' => $this->nextDueAt->toIso8601String(),
            'last_started_at' => $this->lastStartedAt?->toIso8601String(),
            'last_finished_at' => $this->lastFinishedAt?->toIso8601String(),
            'last_exit_code' => $this->lastExitCode,
            'last_failed' => $this->lastFailed,
            'recent' => array_map(fn (RunTick $tick) => $tick->toArray(), $this->recent),
        ];
    }
}
