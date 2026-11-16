<?php

namespace App\Modules\System\Providers;

use App\Modules\System\Commands\CollectSystemCommand;
use App\Modules\System\Listeners\RecordScheduledTask;
use App\Modules\System\Probes\HostProbe;
use App\Modules\System\Probes\ShellHostProbe;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostProbe::class, ShellHostProbe::class);
    }

    public function boot(): void
    {
        $this->commands([CollectSystemCommand::class]);

        Event::listen(
            [ScheduledTaskStarting::class, ScheduledTaskFinished::class, ScheduledTaskFailed::class],
            RecordScheduledTask::class,
        );
    }
}
