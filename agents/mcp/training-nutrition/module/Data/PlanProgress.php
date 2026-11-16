<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PlanProgress
{
    public function __construct(
        public int $planId,
        public ?int $expectedActivePlanId,
        public string $authorizationReference,
        public string $ruleId,
    ) {}
}
