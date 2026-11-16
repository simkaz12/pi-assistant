<?php

namespace App\Modules\System\Services;

use App\Modules\System\Data\PcPeriod;
use App\Modules\System\Data\Problem;
use App\Modules\System\Data\SystemSnapshot;
use App\Modules\System\Enums\Severity;
use App\Modules\System\Enums\SystemEventKind;
use App\Modules\System\Models\SystemEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SystemReader
{
    public const STALE_KEY = 'collector:stale';

    public function __construct(
        private ProblemTracker $tracker,
    ) {}

    public function snapshot(): ?SystemSnapshot
    {
        $stored = Cache::get(SystemCollector::SNAPSHOT_KEY);

        return is_array($stored) ? SystemSnapshot::fromArray($stored) : null;
    }

    /**
     * @return list<Problem>
     */
    public function problems(?SystemSnapshot $snapshot = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $snapshot ??= $this->snapshot();
        $problems = [];

        foreach ($this->tracker->stored() as $key => $stored) {
            $problems[] = Problem::fromStored($key, $stored, $now);
        }

        $stale = $this->stale($snapshot, $now);

        if ($stale !== null) {
            $problems[] = $stale;
        }

        usort($problems, fn (Problem $a, Problem $b) => [$a->severity->rank(), $a->since] <=> [$b->severity->rank(), $b->since]);

        return $problems;
    }

    /**
     * @return Collection<int, SystemEvent>
     */
    public function events(?int $afterId = null, int $limit = 50, ?SystemEventKind $kind = null): Collection
    {
        $query = SystemEvent::query()
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->limit($limit);

        if ($afterId !== null) {
            return $query->where('id', '>', $afterId)->orderBy('id')->get();
        }

        return $query->orderByDesc('id')->get()->reverse()->values();
    }

    /**
     * @return Collection<int, SystemEvent>
     */
    public function latestEvents(int $limit = 100): Collection
    {
        return SystemEvent::query()->orderByDesc('id')->limit($limit)->get();
    }

    public function pcSince(): ?CarbonImmutable
    {
        $latest = SystemEvent::query()
            ->whereIn('kind', [SystemEventKind::PcOn, SystemEventKind::PcOff])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        return $latest === null ? null : CarbonImmutable::instance($latest->occurred_at);
    }

    /**
     * @return list<PcPeriod>
     */
    public function pcPeriods(CarbonImmutable $day): array
    {
        $start = $day->startOfDay();
        $carried = SystemEvent::query()
            ->whereIn('kind', [SystemEventKind::PcOn, SystemEventKind::PcOff])
            ->where('occurred_at', '<', $start)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
        $events = SystemEvent::query()
            ->whereIn('kind', [SystemEventKind::PcOn, SystemEventKind::PcOff])
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $start->addDay())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $periods = [];
        $from = $carried?->kind === SystemEventKind::PcOn ? $start : null;

        foreach ($events as $event) {
            $at = CarbonImmutable::instance($event->occurred_at);

            if ($event->kind === SystemEventKind::PcOn && $from === null) {
                $from = $at;
            } elseif ($event->kind === SystemEventKind::PcOff && $from !== null) {
                $periods[] = new PcPeriod($from, $at);
                $from = null;
            }
        }

        if ($from !== null) {
            $periods[] = new PcPeriod($from, null);
        }

        return $periods;
    }

    private function stale(?SystemSnapshot $snapshot, CarbonImmutable $now): ?Problem
    {
        $minutes = (int) config('system.stale_after_minutes', 3);

        if ($snapshot === null) {
            return Problem::at(
                self::STALE_KEY,
                Severity::Critical,
                'System collector has never run',
                'no snapshot in the cache',
                $this->missingSince($now),
                $now,
            );
        }

        $staleSince = $snapshot->collectedAt->addMinutes($minutes);

        if ($staleSince->greaterThanOrEqualTo($now)) {
            return null;
        }

        return Problem::at(
            self::STALE_KEY,
            Severity::Critical,
            'System collector has stopped',
            'last snapshot at '.$snapshot->collectedAt->toIso8601String(),
            $staleSince,
            $now,
        );
    }

    private function missingSince(CarbonImmutable $now): CarbonImmutable
    {
        Cache::add(SystemCollector::MISSING_SINCE_KEY, $now->toIso8601String());

        return CarbonImmutable::parse(Cache::get(SystemCollector::MISSING_SINCE_KEY));
    }
}
