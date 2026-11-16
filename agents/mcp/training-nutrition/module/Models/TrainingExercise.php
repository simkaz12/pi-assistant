<?php

namespace App\Modules\TrainingNutrition\Models;

use App\Modules\TrainingNutrition\Models\Concerns\BelongsToTrainingOwner;
use Database\Factories\Modules\TrainingNutrition\Models\TrainingExerciseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrainingExercise extends Model
{
    use BelongsToTrainingOwner;
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'aliases' => 'array',
            'metadata' => 'array',
            'measures' => 'array',
        ];
    }

    protected static function newFactory(): TrainingExerciseFactory
    {
        return TrainingExerciseFactory::new();
    }
}
