<?php

namespace App\Modules\System\Data;

use Carbon\CarbonImmutable;

final readonly class QueueStatus
{
    public const BACKLOG_LIMIT = 100;

    public const BACKLOG_MINUTES = 10;

    public function __construct(
        public ?int $backlog,
        public ?CarbonImmutable $overSince,
        public int $failedJobs,
        public ?string $newestFailedClass,
    ) {}

    public function backlogSustained(CarbonImmutable $now): bool
    {
        return $this->overSince !== null
            && $this->overSince->diffInSeconds($now, false) >= self::BACKLOG_MINUTES * 60;
    }

    /**
     * @param  array{backlog: int|null, over_since: string|null, failed_jobs: int, newest_failed_class: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['backlog'],
            $data['over_since'] === null ? null : CarbonImmutable::parse($data['over_since']),
            $data['failed_jobs'],
            $data['newest_failed_class'],
        );
    }

    /**
     * @return array{backlog: int|null, over_since: string|null, failed_jobs: int, newest_failed_class: string|null}
     */
    public function toArray(): array
    {
        return [
            'backlog' => $this->backlog,
            'over_since' => $this->overSince?->toIso8601String(),
            'failed_jobs' => $this->failedJobs,
            'newest_failed_class' => $this->newestFailedClass,
        ];
    }
}
