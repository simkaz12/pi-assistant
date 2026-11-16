<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PlanScheduleSlot
{
    public function __construct(
        public string $slotId,
        public string $date,
        public ActivityType $activityType,
        public TrainingIntensity $intensity,
        public bool $optional,
    ) {}

    public static function fromStored(array $slot): self
    {
        return new self(
            $slot['slot_id'],
            $slot['date'],
            ActivityType::from($slot['activity_type']),
            TrainingIntensity::from($slot['intensity']),
            $slot['optional'],
        );
    }

    public function toArray(): array
    {
        return [
            'slot_id' => $this->slotId,
            'date' => $this->date,
            'activity_type' => $this->activityType->value,
            'intensity' => $this->intensity->value,
            'optional' => $this->optional,
        ];
    }
}
