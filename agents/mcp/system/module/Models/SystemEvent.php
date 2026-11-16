<?php

namespace App\Modules\System\Models;

use App\Modules\System\Enums\Severity;
use App\Modules\System\Enums\SystemEventKind;
use Database\Factories\SystemEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class SystemEvent extends Model
{
    use HasFactory, MassPrunable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => SystemEventKind::class,
            'severity' => Severity::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        $days = (int) config('system.event_retention_days', 90);

        return static::query()->where('occurred_at', '<', now()->subDays($days));
    }

    protected static function newFactory(): SystemEventFactory
    {
        return SystemEventFactory::new();
    }
}
