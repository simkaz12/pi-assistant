<?php

namespace App\Modules\TrainingNutrition\Data;

enum RecordKind: string
{
    case Profile = 'profile';
    case Exercise = 'exercise';
    case Plan = 'plan';
    case Session = 'session';
    case Link = 'link';
    case Nutrition = 'nutrition';
}
