<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class WorkoutSet
{
    /** @param list<'load_kg'|'duration_minutes'|'distance_km'> $present */
    public function __construct(
        public int $reps,
        public int|float|null $loadKg,
        public int|float|null $durationMinutes,
        public int|float|null $distanceKm,
        public array $present,
    ) {}

    public static function fromValidated(array $set): self
    {
        return new self(
            $set['reps'],
            $set['load_kg'] ?? null,
            $set['duration_minutes'] ?? null,
            $set['distance_km'] ?? null,
            array_values(array_intersect(
                ['load_kg', 'duration_minutes', 'distance_km'],
                array_keys($set),
            )),
        );
    }

    public function toArray(): array
    {
        $set = ['reps' => $this->reps];

        foreach ($this->present as $field) {
            $set[$field] = match ($field) {
                'load_kg' => $this->loadKg,
                'duration_minutes' => $this->durationMinutes,
                'distance_km' => $this->distanceKm,
            };
        }

        return $set;
    }
}
