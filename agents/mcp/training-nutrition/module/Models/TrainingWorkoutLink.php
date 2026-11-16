<?php

namespace App\Modules\TrainingNutrition\Models;

use App\Modules\Oura\Models\OuraConnection;
use App\Modules\TrainingNutrition\Models\Concerns\BelongsToTrainingOwner;
use Database\Factories\Modules\TrainingNutrition\Models\TrainingWorkoutLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingWorkoutLink extends Model
{
    use BelongsToTrainingOwner;
    use HasFactory;

    protected $guarded = [];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(OuraConnection::class, 'oura_connection_id');
    }

    protected function casts(): array
    {
        return [
            'oura_connection_id' => 'integer',
            'session_id' => 'integer',
            'source_revision' => 'integer',
            'candidate_ids' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TrainingWorkoutLinkFactory
    {
        return TrainingWorkoutLinkFactory::new();
    }
}
