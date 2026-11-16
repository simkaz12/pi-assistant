<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class SessionStart
{
    /** @param list<'strength'|'run'> $activities */
    public function __construct(
        public ?int $planId,
        public ?string $slotId,
        public ActivityType $activityType,
        public array $activities,
        public ?string $location,
        public ?string $startedAt,
        public ?string $endedAt,
        public SessionTimePrecision $timePrecision,
        public ?string $subjectiveNotes,
    ) {}
}
