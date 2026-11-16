<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PlanAccept
{
    public function __construct(
        public int $planId,
        public ?int $expectedActivePlanId,
        public string $authorizationReference,
    ) {}
}
