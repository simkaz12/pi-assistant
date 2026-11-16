<?php

namespace App\Modules\System\Enums;

enum SwitchKind: string
{
    case Hermes = 'hermes';
    case Schedule = 'schedule';
    case Stack = 'stack';

    public function runsOn(): string
    {
        return match ($this) {
            self::Hermes => 'hermes cron',
            self::Schedule => 'laravel schedule',
            self::Stack => 'docker',
        };
    }

    public function appliedByJob(): bool
    {
        return $this !== self::Schedule;
    }
}
