<?php

namespace App\Modules\TrainingNutrition\Models;

use App\Modules\TrainingNutrition\Models\Concerns\BelongsToTrainingOwner;
use Database\Factories\Modules\TrainingNutrition\Models\TrainingNutritionOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrainingNutritionOperation extends Model
{
    use BelongsToTrainingOwner;
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'scope' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TrainingNutritionOperationFactory
    {
        return TrainingNutritionOperationFactory::new();
    }
}
