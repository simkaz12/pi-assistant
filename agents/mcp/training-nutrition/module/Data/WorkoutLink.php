<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class WorkoutLink
{
    public function __construct(
        public int $sessionId,
        public int $expectedVersion,
        public int $ouraConnectionId,
        public string $sourceId,
    ) {}
}
