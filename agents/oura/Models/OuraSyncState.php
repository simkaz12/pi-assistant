<?php

namespace App\Modules\Oura\Models;

use Database\Factories\OuraSyncStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OuraSyncState extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'window_start' => 'date:Y-m-d',
            'window_end' => 'date:Y-m-d',
            'history_floor' => 'date:Y-m-d',
            'reconcile_cursor' => 'date:Y-m-d',
            'last_successful_sync_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OuraSyncStateFactory
    {
        return OuraSyncStateFactory::new();
    }
}
