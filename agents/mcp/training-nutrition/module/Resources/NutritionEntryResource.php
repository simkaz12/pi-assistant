<?php

namespace App\Modules\TrainingNutrition\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NutritionEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'observed_date' => $this->observed_date?->toDateString(),
            'time_precision' => $this->time_precision,
            'time_offset' => $this->time_offset,
            'meal_period' => $this->meal_period,
            'category' => $this->category,
            'description' => $this->description,
            'items' => $this->items,
            'context' => $this->context,
            'evidence_kind' => $this->evidence_kind,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
