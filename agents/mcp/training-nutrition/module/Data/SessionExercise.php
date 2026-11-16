<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class SessionExercise
{
    /** @param list<RepetitionSet> $sets */
    public function __construct(public int $exerciseId, public array $sets) {}

    public static function fromValidated(array $exercise): self
    {
        return new self(
            $exercise['exercise_id'],
            array_map(RepetitionSet::fromValidated(...), $exercise['sets']),
        );
    }

    public function setsToArray(): array
    {
        return array_map(fn (RepetitionSet $set): array => $set->toArray(), $this->sets);
    }
}
