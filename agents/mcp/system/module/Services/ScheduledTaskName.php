<?php

namespace App\Modules\System\Services;

use Illuminate\Console\Scheduling\Event;

class ScheduledTaskName
{
    public static function for(Event $event): string
    {
        $description = trim((string) $event->description);

        if ($description !== '') {
            return $description;
        }

        $command = preg_replace(
            '/^(?:\'[^\']*\'|"[^"]*"|\S+)\s+(?:\'artisan\'|"artisan"|artisan)\s+/',
            '',
            (string) $event->command,
        );

        return trim(str_replace(["'", '"'], '', $command));
    }

    public static function cacheKey(string $name): string
    {
        return 'system:schedule:'.sha1($name);
    }
}
