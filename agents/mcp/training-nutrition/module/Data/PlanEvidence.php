<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PlanEvidence
{
    public function __construct(
        public PlanEvidenceKind $kind,
        public string $reference,
        public ?string $date,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind->value,
            'reference' => $this->reference,
            'date' => $this->date,
        ], fn ($value) => $value !== null);
    }
}
