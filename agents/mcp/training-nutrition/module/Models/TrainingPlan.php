<?php

namespace App\Modules\TrainingNutrition\Models;

use App\Modules\TrainingNutrition\Models\Concerns\BelongsToTrainingOwner;
use Database\Factories\Modules\TrainingNutrition\Models\TrainingPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingPlan extends Model
{
    use BelongsToTrainingOwner;
    use HasFactory;

    protected $guarded = [];

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_plan_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class, 'plan_id');
    }

    protected function casts(): array
    {
        return [
            'prior_plan_id' => 'integer',
            'direction' => 'array',
            'slots' => 'array',
            'progression_rules' => 'array',
            'evidence' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TrainingPlanFactory
    {
        return TrainingPlanFactory::new();
    }
}
