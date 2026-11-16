<?php

namespace App\Modules\TrainingNutrition\Models;

use App\Modules\TrainingNutrition\Models\Concerns\BelongsToTrainingOwner;
use Database\Factories\Modules\TrainingNutrition\Models\TrainingProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingProfile extends Model
{
    use BelongsToTrainingOwner;
    use HasFactory;

    protected $guarded = [];

    public function activePlan(): BelongsTo
    {
        return $this->belongsTo(TrainingPlan::class, 'active_plan_id');
    }

    protected function casts(): array
    {
        return [
            'active_plan_id' => 'integer',
            'version' => 'integer',
            'goals' => 'array',
            'constraints' => 'array',
            'preferences' => 'array',
            'locations' => 'array',
        ];
    }

    protected static function newFactory(): TrainingProfileFactory
    {
        return TrainingProfileFactory::new();
    }
}
