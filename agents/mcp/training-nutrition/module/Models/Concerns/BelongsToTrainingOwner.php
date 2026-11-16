<?php

namespace App\Modules\TrainingNutrition\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTrainingOwner
{
    public function scopeForOwner(Builder $query, int $ownerId): Builder
    {
        return $query->where('user_id', $ownerId);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
