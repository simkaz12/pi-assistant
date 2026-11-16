<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class RepetitionSet
{
    public function __construct(
        public int $reps,
        public int|float|null $loadKg,
        public bool $hasLoadKg,
    ) {}

    public static function fromValidated(array $set): self
    {
        return new self($set['reps'], $set['load_kg'] ?? null, array_key_exists('load_kg', $set));
    }

    public function toArray(): array
    {
        $set = ['reps' => $this->reps];

        if ($this->hasLoadKg) {
            $set['load_kg'] = $this->loadKg;
        }

        return $set;
    }
}
