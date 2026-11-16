<?php

namespace App\Modules\System\Resources;

use App\Modules\System\Models\SystemEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SystemEvent
 */
class SystemEventResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'problem_key' => $this->problem_key,
            'severity' => $this->severity?->value,
            'title' => $this->title,
            'body' => $this->body,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
