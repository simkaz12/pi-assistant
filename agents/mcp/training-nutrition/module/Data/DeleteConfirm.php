<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class DeleteConfirm
{
    public function __construct(public string $confirmationToken) {}
}
