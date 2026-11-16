<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PlanDirection
{
    public function __construct(public string $focus, public int $weeks) {}

    public function toArray(): array
    {
        return ['focus' => $this->focus, 'weeks' => $this->weeks];
    }
}
