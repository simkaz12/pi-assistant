<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PlanExercise
{
    public function __construct(
        public int $exerciseId,
        public int $sets,
        public ?int $reps,
        public int|float|null $loadKg,
        public int|float|null $durationMinutes,
    ) {}

    public static function fromValidated(array $exercise): self
    {
        return new self(
            $exercise['exercise_id'],
            $exercise['sets'],
            $exercise['reps'] ?? null,
            $exercise['load_kg'] ?? null,
            $exercise['duration_minutes'] ?? null,
        );
    }

    public function toArray(array $catalogueSnapshot): array
    {
        return array_filter([
            'exercise_id' => $this->exerciseId,
            'sets' => $this->sets,
            'reps' => $this->reps,
            'load_kg' => $this->loadKg,
            'duration_minutes' => $this->durationMinutes,
            'catalogue_snapshot' => $catalogueSnapshot,
        ], fn ($value) => $value !== null);
    }
}
