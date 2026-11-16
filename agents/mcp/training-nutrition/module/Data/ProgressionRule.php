<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class ProgressionRule
{
    public function __construct(public string $ruleId, public string $text) {}

    public function toArray(): array
    {
        return ['rule_id' => $this->ruleId, 'text' => $this->text];
    }
}
