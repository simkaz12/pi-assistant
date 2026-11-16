<?php

namespace App\Modules\TrainingNutrition\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingWorkoutLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'oura_connection_id' => $this->oura_connection_id,
            'dataset' => $this->dataset,
            'source_id' => $this->source_id,
            'session_id' => $this->session_id,
            'state' => $this->state,
            'method' => $this->method,
            'candidate_ids' => $this->candidate_ids,
            'overlap' => $this->overlap,
            'source_revision' => $this->source_revision,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
