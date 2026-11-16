<?php

namespace App\Modules\Oura\Models;

use Database\Factories\OuraConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OuraConnection extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'refresh_started_at' => 'datetime',
            'state_expires_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OuraConnectionFactory
    {
        return OuraConnectionFactory::new();
    }
}
