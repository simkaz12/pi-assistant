<?php

namespace App\Modules\TrainingNutrition\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'timezone' => $this->timezone,
            'goals' => $this->goals,
            'constraints' => $this->constraints,
            'preferences' => $this->preferences,
            'locations' => $this->locations,
            'active_plan_id' => $this->active_plan_id,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
