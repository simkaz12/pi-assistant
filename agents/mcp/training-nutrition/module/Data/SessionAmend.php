<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class SessionAmend
{
    /**
     * @param  list<string>  $present
     * @param  list<'strength'|'run'>|null  $activities
     * @param  list<SessionExercise>|null  $actual
     */
    public function __construct(
        public int $sessionId,
        public int $expectedVersion,
        public array $present,
        public ?string $startedAt,
        public ?string $endedAt,
        public ?SessionTimePrecision $timePrecision,
        public ?ActivityType $activityType,
        public ?array $activities,
        public ?string $location,
        public ?string $subjectiveNotes,
        public ?array $actual,
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
