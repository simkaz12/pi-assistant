<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class SessionReconcile
{
    public function __construct(public int $limit) {}
}
