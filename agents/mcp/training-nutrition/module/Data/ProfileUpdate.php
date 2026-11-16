<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class ProfileUpdate
{
    /**
     * @param  list<string>|null  $goals
     * @param  list<string>|null  $constraints
     * @param  array{session_minutes?: int, full_body?: bool, weekly_pattern?: list<string>, weekend_optional?: bool, running_weekday?: string, running_weekend_optional?: bool}|null  $preferences
     * @param  list<array{name: string, equipment: list<string>}>|null  $locations
     */
    public function __construct(
        public int $expectedVersion,
        public ?string $timezone,
        public ?array $goals,
        public ?array $constraints,
        public ?array $preferences,
        public ?array $locations,
    ) {}
}
