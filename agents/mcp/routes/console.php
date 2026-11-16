<?php

use App\Modules\System\Models\SystemEvent;
use App\Modules\System\Services\Switches;
use Illuminate\Support\Facades\Schedule;

$off = fn (string $key) => fn () => ! app(Switches::class)->isOn($key);

Schedule::command('oura:sync recent')->hourly()->withoutOverlapping()->skip($off('oura-sync'));
Schedule::command('oura:sync history')->dailyAt('02:00')->withoutOverlapping()->skip($off('oura-sync'));
Schedule::command('oura:sync older')->dailyAt('02:30')->withoutOverlapping()->skip($off('oura-sync'));
Schedule::command('oura:sync prune')->dailyAt('03:30')->withoutOverlapping()->skip($off('oura-sync'));
Schedule::command('training:reconcile-workouts')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->skip($off('training-reconcile'));
Schedule::command('system:collect')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => SystemEvent::class])->dailyAt('03:50');
