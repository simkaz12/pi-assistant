<?php

namespace App\Modules\TrainingNutrition\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'prior_plan_id' => $this->prior_plan_id,
            'title' => $this->title,
            'direction' => $this->direction,
            'slots' => $this->slots,
            'progression_rules' => $this->progression_rules,
            'rationale' => $this->rationale,
            'evidence' => $this->evidence,
            'activation_basis' => $this->activation_basis,
            'activation_reference' => $this->activation_reference,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
