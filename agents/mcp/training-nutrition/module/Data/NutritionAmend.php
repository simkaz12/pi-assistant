<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class NutritionAmend
{
    /**
     * @param  list<NutritionItem>|null  $items
     * @param  array{hunger?: string, digestion?: string, energy?: string}|null  $context
     */
    public function __construct(
        public int $entryId,
        public int $expectedVersion,
        public ?NutritionTimePrecision $timePrecision,
        public ?string $observedAt,
        public ?string $observedDate,
        public ?string $mealPeriod,
        public ?NutritionCategory $category,
        public ?string $description,
        public ?array $items,
        public ?array $context,
        public ?NutritionEvidenceKind $evidenceKind,
    ) {}
}
