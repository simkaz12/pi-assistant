<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class NutritionLabel
{
    public function __construct(
        public int|float|null $value,
        public ?string $unit,
        public ?string $basis,
        public ?string $servingSize,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'value' => $this->value,
            'unit' => $this->unit,
            'basis' => $this->basis,
            'serving_size' => $this->servingSize,
        ], fn ($value) => $value !== null);
    }
}
