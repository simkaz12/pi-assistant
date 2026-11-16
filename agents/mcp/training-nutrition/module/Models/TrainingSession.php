<?php

namespace App\Modules\TrainingNutrition\Models;

use App\Modules\TrainingNutrition\Casts\OwnerLocalTime;
use App\Modules\TrainingNutrition\Models\Concerns\BelongsToTrainingOwner;
use Database\Factories\Modules\TrainingNutrition\Models\TrainingSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingSession extends Model
{
    use BelongsToTrainingOwner;
    use HasFactory;

    protected $guarded = [];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TrainingPlan::class);
    }

    public function workoutLinks(): HasMany
    {
        return $this->hasMany(TrainingWorkoutLink::class, 'session_id');
    }

    public function canonicalSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'merged_into_id' => 'integer',
            'version' => 'integer',
            'prescription' => 'array',
            'actual' => 'array',
            'activities' => 'array',
            'session_day' => 'date:Y-m-d',
            'started_at' => OwnerLocalTime::class,
            'ended_at' => OwnerLocalTime::class,
        ];
    }

    protected static function newFactory(): TrainingSessionFactory
    {
        return TrainingSessionFactory::new();
    }
}
