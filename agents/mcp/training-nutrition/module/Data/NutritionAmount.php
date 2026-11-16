<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class NutritionAmount
{
    public function __construct(
        public int|float|null $value,
        public ?string $unit,
        public ?string $basis,
        public ?string $source,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'value' => $this->value,
            'unit' => $this->unit,
            'basis' => $this->basis,
            'source' => $this->source,
        ], fn ($value) => $value !== null);
    }
}
