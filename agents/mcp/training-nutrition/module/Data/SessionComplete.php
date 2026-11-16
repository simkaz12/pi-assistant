<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class SessionComplete
{
    /** @param list<SessionExercise> $exceptions */
    public function __construct(
        public int $sessionId,
        public int $expectedVersion,
        public ?string $endedAt,
        public bool $asPrescribed,
        public array $exceptions,
        public ?string $subjectiveNotes,
        public bool $hasSubjectiveNotes,
    ) {}
}
