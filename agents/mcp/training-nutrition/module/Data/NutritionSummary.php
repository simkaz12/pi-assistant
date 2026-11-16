<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class NutritionSummary
{
    public function __construct(public string $from, public string $through) {}
}
