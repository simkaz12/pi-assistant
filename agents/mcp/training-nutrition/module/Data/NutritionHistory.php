<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class NutritionHistory
{
    public function __construct(
        public ?string $from,
        public ?string $through,
        public ?string $cursor,
        public int $limit,
    ) {}
}
