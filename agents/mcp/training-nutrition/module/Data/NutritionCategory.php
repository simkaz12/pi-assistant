<?php

namespace App\Modules\TrainingNutrition\Data;

enum NutritionCategory: string
{
    case Meal = 'meal';
    case Drink = 'drink';
    case Caffeine = 'caffeine';
    case Alcohol = 'alcohol';
    case Other = 'other';
}
