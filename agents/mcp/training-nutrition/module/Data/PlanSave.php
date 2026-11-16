<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PlanSave
{
    /**
     * @param  list<PlanSlot>  $slots
     * @param  list<ProgressionRule>  $progressionRules
     * @param  list<PlanEvidence>  $evidence
     */
    public function __construct(
        public ?int $priorPlanId,
        public string $title,
        public PlanDirection $direction,
        public array $slots,
        public array $progressionRules,
        public ?string $rationale,
        public array $evidence,
    ) {}
}
