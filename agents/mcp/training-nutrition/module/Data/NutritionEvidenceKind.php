<?php

namespace App\Modules\TrainingNutrition\Data;

enum NutritionEvidenceKind: string
{
    case OwnerReported = 'owner_reported';
    case LabelTranscribed = 'label_transcribed';
    case ImageInferred = 'image_inferred';
}
