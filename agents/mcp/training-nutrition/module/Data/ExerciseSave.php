<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class ExerciseSave
{
    /**
     * @param  list<string>|null  $aliases
     * @param  array{movement?: string, equipment?: list<string>, body_region?: string}|null  $metadata
     * @param  list<'reps'|'load_kg'|'duration_minutes'|'distance_km'|'sets'>  $measures
     */
    public function __construct(
        public ?int $exerciseId,
        public int $expectedVersion,
        public string $name,
        public ?array $aliases,
        public ?array $metadata,
        public array $measures,
    ) {}
}
