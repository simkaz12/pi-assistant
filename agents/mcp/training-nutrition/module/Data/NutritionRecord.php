<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class NutritionRecord
{
    /**
     * @param  list<NutritionItem>  $items
     * @param  array{hunger?: string, digestion?: string, energy?: string}  $context
     */
    public function __construct(
        public NutritionTimePrecision $timePrecision,
        public ?string $observedAt,
        public ?string $observedDate,
        public ?string $mealPeriod,
        public NutritionCategory $category,
        public ?string $description,
        public array $items,
        public array $context,
        public NutritionEvidenceKind $evidenceKind,
    ) {}
}
