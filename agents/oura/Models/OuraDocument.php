<?php

namespace App\Modules\Oura\Models;

use Database\Factories\OuraDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OuraDocument extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'source_day' => 'date:Y-m-d',
            'source_at' => 'datetime',
            'fetched_at' => 'datetime',
            'source_deleted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OuraDocumentFactory
    {
        return OuraDocumentFactory::new();
    }
}
