<?php

namespace App\Modules\System\Enums;

enum Severity: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 1,
            self::Warning => 2,
        };
    }
}
