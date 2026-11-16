<?php

namespace App\Modules\TrainingNutrition\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'plan_id' => $this->plan_id,
            'slot_id' => $this->slot_id,
            'prescription' => $this->prescription,
            'actual' => $this->actual,
            'activities' => $this->activities,
            'activity_type' => $this->activity_type,
            'location' => $this->location,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'session_day' => $this->session_day?->toDateString(),
            'time_precision' => $this->time_precision,
            'state' => $this->state,
            'provenance' => $this->provenance,
            'subjective_notes' => $this->subjective_notes,
            'version' => $this->version,
            'merged_into_id' => $this->merged_into_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
