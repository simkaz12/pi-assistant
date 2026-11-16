<?php

namespace App\Modules\TrainingNutrition\Models;

use App\Modules\TrainingNutrition\Casts\OwnerLocalTime;
use App\Modules\TrainingNutrition\Models\Concerns\BelongsToTrainingOwner;
use Database\Factories\Modules\TrainingNutrition\Models\NutritionEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NutritionEntry extends Model
{
    use BelongsToTrainingOwner;
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'items' => 'array',
            'context' => 'array',
            'observed_date' => 'date:Y-m-d',
            'observed_at' => OwnerLocalTime::class,
        ];
    }

    protected static function newFactory(): NutritionEntryFactory
    {
        return NutritionEntryFactory::new();
    }
}
