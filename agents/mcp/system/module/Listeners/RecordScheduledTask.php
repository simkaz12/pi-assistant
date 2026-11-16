<?php

namespace App\Modules\System\Listeners;

use App\Modules\System\Services\ScheduledTaskName;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Cache;

class RecordScheduledTask
{
    public const RING_SIZE = 5;

    public function handle(ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskFailed $event): void
    {
        $name = ScheduledTaskName::for($event->task);
        $key = ScheduledTaskName::cacheKey($name);
        $record = Cache::get($key, []);

        $record = match (true) {
            $event instanceof ScheduledTaskStarting => $this->started($record),
            $event instanceof ScheduledTaskFinished => $this->finished($record, (int) $event->task->exitCode),
            default => $this->failed($record, $event->task->exitCode),
        };

        Cache::forever($key, ['name' => $name, ...$record]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function started(array $record): array
    {
        return [...$record, 'started_at' => now()->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function finished(array $record, int $exitCode): array
    {
        $finishedAt = now()->toIso8601String();

        return [
            ...$record,
            'finished_at' => $finishedAt,
            'exit_code' => $exitCode,
            'failed' => $exitCode !== 0,
            'recent' => $this->ring($record, $finishedAt, $exitCode),
            'recorded_run' => $record['started_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function failed(array $record, mixed $exitCode): array
    {
        $finishedAt = now()->toIso8601String();
        $code = is_int($exitCode) && $exitCode !== 0 ? $exitCode : 1;

        return [
            ...$record,
            'finished_at' => $finishedAt,
            'exit_code' => $code,
            'failed' => true,
            'recent' => $this->alreadyRecorded($record)
                ? $this->replaceLast($record, $finishedAt, $code)
                : $this->ring($record, $finishedAt, $code),
            'recorded_run' => $record['started_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function alreadyRecorded(array $record): bool
    {
        $run = $record['started_at'] ?? null;

        return $run !== null && ($record['recorded_run'] ?? null) === $run && ($record['recent'] ?? []) !== [];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{finished_at: string, exit_code: int}>
     */
    private function replaceLast(array $record, string $finishedAt, int $exitCode): array
    {
        $recent = $record['recent'];
        $recent[array_key_last($recent)] = ['finished_at' => $finishedAt, 'exit_code' => $exitCode];

        return $recent;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{finished_at: string, exit_code: int}>
     */
    private function ring(array $record, string $finishedAt, int $exitCode): array
    {
        $recent = [...($record['recent'] ?? []), ['finished_at' => $finishedAt, 'exit_code' => $exitCode]];

        return array_slice($recent, -self::RING_SIZE);
    }
}
