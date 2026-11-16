<?php

namespace App\Modules\System\Models;

use Database\Factories\SystemSwitchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSwitch extends Model
{
    use HasFactory;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'on' => 'boolean',
            'changed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SystemSwitchFactory
    {
        return SystemSwitchFactory::new();
    }
}
