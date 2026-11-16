<?php

namespace App\Modules\TrainingNutrition\Services;

use RuntimeException;

class TrainingNutritionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
