<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PlanSlot
{
    /** @param list<PlanExercise> $exercises */
    public function __construct(public PlanScheduleSlot $schedule, public array $exercises) {}

    public static function fromValidated(array $slot): self
    {
        return new self(
            PlanScheduleSlot::fromStored($slot),
            array_map(PlanExercise::fromValidated(...), $slot['exercises'] ?? []),
        );
    }

    /** @param list<array<string, int|float|string|array>> $prescriptions */
    public function toArray(array $prescriptions): array
    {
        return $this->schedule->toArray() + ['exercises' => $prescriptions];
    }
}
