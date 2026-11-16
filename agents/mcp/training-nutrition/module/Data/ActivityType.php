<?php

namespace App\Modules\TrainingNutrition\Data;

enum ActivityType: string
{
    case Strength = 'strength';
    case Run = 'run';
    case Mixed = 'mixed';
    case Other = 'other';
}
