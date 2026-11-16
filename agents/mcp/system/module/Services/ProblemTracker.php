<?php

namespace App\Modules\System\Services;

use App\Modules\System\Data\Finding;
use App\Modules\System\Enums\SystemEventKind;
use App\Modules\System\Models\SystemEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class ProblemTracker
{
    public const CACHE_KEY = 'system:problems';

    /**
     * @param  list<Finding>  $findings
     */
    public function sync(array $findings, CarbonImmutable $now): void
    {
        $stored = $this->stored();
        $next = [];

        foreach ($findings as $finding) {
            $previous = $stored[$finding->key] ?? null;
            $changed = $previous === null || $previous['severity'] !== $finding->severity->value;

            if ($changed) {
                $this->recordOpened($finding, $now);
            }

            $next[$finding->key] = [
                'severity' => $finding->severity->value,
                'title' => $finding->title,
                'detail' => $finding->detail,
                'since' => $changed ? $now->toIso8601String() : $previous['since'],
            ];
        }

        foreach (array_diff_key($stored, $next) as $key => $gone) {
            $this->recordResolved($key, $gone, $now);
        }

        Cache::forever(self::CACHE_KEY, $next);
    }

    /**
     * @return array<string, array{severity: string, title: string, detail: string, since: string}>
     */
    public function stored(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }

    private function recordOpened(Finding $finding, CarbonImmutable $now): void
    {
        SystemEvent::query()->create([
            'kind' => SystemEventKind::ProblemOpened,
            'problem_key' => $finding->key,
            'severity' => $finding->severity,
            'title' => $finding->title,
            'body' => $finding->detail,
            'occurred_at' => $now,
        ]);
    }

    /**
     * @param  array{severity: string, title: string, detail: string, since: string}  $problem
     */
    private function recordResolved(string $key, array $problem, CarbonImmutable $now): void
    {
        SystemEvent::query()->create([
            'kind' => SystemEventKind::ProblemResolved,
            'problem_key' => $key,
            'severity' => null,
            'title' => $problem['title'],
            'body' => $problem['detail'],
            'occurred_at' => $now,
        ]);
    }
}
