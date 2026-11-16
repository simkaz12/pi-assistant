<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PlanGet
{
    public function __construct(public ?int $planId) {}
}
