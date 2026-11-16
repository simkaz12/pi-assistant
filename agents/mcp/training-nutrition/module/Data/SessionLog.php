<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class SessionLog
{
    /** @param non-empty-list<WorkoutSet> $sets */
    public function __construct(
        public int $sessionId,
        public int $expectedVersion,
        public int $exerciseId,
        public array $sets,
    ) {}
}
