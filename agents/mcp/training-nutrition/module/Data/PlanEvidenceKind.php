<?php

namespace App\Modules\TrainingNutrition\Data;

enum PlanEvidenceKind: string
{
    case Oura = 'oura';
    case Session = 'session';
    case Nutrition = 'nutrition';
    case OwnerReport = 'owner_report';
}
