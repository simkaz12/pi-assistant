<?php

namespace App\Modules\TrainingNutrition\Data;

enum SessionTimePrecision: string
{
    case Exact = 'exact';
    case Date = 'date';
}
