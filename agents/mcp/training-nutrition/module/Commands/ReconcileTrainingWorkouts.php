<?php

namespace App\Modules\TrainingNutrition\Commands;

use App\Models\User;
use App\Modules\TrainingNutrition\Services\TrainingWorkoutReconciliationService;
use Illuminate\Console\Command;

class ReconcileTrainingWorkouts extends Command
{
    protected $signature = 'training:reconcile-workouts {--limit=50}';

    protected $description = 'Reconcile one bounded batch of stored Oura workouts with training sessions';

    public function handle(TrainingWorkoutReconciliationService $reconciliation): int
    {
        $owner = User::find(config('training_nutrition.user_id'));
        if (! $owner) {
            $this->error('Training owner is not configured.');

            return self::FAILURE;
        }

        $limit = min(100, max(1, (int) $this->option('limit')));
        $this->line(json_encode($reconciliation->batch($owner->id, $limit), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
