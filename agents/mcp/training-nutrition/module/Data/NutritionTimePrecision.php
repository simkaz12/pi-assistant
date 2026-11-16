<?php

namespace App\Modules\TrainingNutrition\Data;

enum NutritionTimePrecision: string
{
    case Exact = 'exact';
    case Approximate = 'approximate';
    case Date = 'date';
    case MealPeriod = 'meal_period';
}
