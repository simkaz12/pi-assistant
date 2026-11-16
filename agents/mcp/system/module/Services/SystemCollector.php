<?php

namespace App\Modules\System\Services;

use App\Modules\Pc\Services\PcService;
use App\Modules\Pc\Services\WolRelayService;
use App\Modules\Pi\Services\PiSystemService;
use App\Modules\System\Data\ContainerStatus;
use App\Modules\System\Data\DynamicDnsStatus;
use App\Modules\System\Data\HermesJob;
use App\Modules\System\Data\QueueStatus;
use App\Modules\System\Data\RunTick;
use App\Modules\System\Data\ScheduleStatus;
use App\Modules\System\Data\SwitchStatus;
use App\Modules\System\Data\SystemSnapshot;
use App\Modules\System\Enums\SwitchKind;
use App\Modules\System\Enums\SystemEventKind;
use App\Modules\System\Exceptions\ProbeFailed;
use App\Modules\System\Models\FailedJob;
use App\Modules\System\Models\SystemEvent;
use App\Modules\System\Probes\HostProbe;
use Carbon\CarbonImmutable;
use Closure;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

class SystemCollector
{
    public const SNAPSHOT_KEY = 'system:snapshot';

    public const MISSING_SINCE_KEY = 'system:snapshot-missing-since';

    private const SLOW_PROBES_KEY = 'system:slow-probes';

    private const OVER_SINCE_KEY = 'system:queue-over-since';

    private const FIRST_SEEN_KEY = 'system:schedule-first-seen:';

    public function __construct(
        private HostProbe $probe,
        private PiSystemService $pi,
        private PcService $pc,
        private WolRelayService $relay,
        private Schedule $schedule,
        private HealthRules $rules,
        private ProblemTracker $tracker,
        private Switches $switches,
    ) {}

    public function collect(): SystemSnapshot
    {
        $now = CarbonImmutable::now();
        $errors = [];

        $previousPcOnline = $this->previousPcOnline();

        $units = $this->attempt('units', fn () => $this->probe->unitStates(config('system.units')), [], $errors);
        $containers = $this->attempt('containers', fn () => $this->probe->containers(), [], $errors);
        $peers = $this->attempt('wireguard', fn () => $this->probe->wireguardPeers(), [], $errors);
        $backlog = $this->attempt('queue', fn () => $this->probe->queueBacklog(), null, $errors);
        $jobs = $this->hermesJobs($errors);
        $slow = $this->slowProbes();
        $schedule = $this->scheduleStatuses($now);
        $pcOnline = $this->pc->isOnline();

        $snapshot = new SystemSnapshot(
            $now,
            $this->pi->stats(),
            $units,
            $containers,
            $schedule,
            $this->queueStatus($backlog, $now),
            $slow['cert_days'],
            $slow['ddns'] === null ? null : DynamicDnsStatus::fromArray($slow['ddns']),
            $peers,
            $pcOnline,
            $this->relay->isOnline(),
            [...$errors, ...$slow['errors']],
            $this->switchStatuses($schedule, $containers, $jobs),
        );

        $this->recordPcFlip($previousPcOnline, $pcOnline, $now);

        Cache::forever(self::SNAPSHOT_KEY, $snapshot->toArray());
        Cache::forget(self::MISSING_SINCE_KEY);
        $this->tracker->sync($this->rules->evaluate($snapshot, $now), $now);

        return $snapshot;
    }

    private function previousPcOnline(): ?bool
    {
        $stored = Cache::get(self::SNAPSHOT_KEY);

        return is_array($stored) && is_bool($stored['pc_online'] ?? null) ? $stored['pc_online'] : null;
    }

    private function recordPcFlip(?bool $previous, bool $online, CarbonImmutable $now): void
    {
        if ($previous === null || $previous === $online) {
            return;
        }

        SystemEvent::query()->create([
            'kind' => $online ? SystemEventKind::PcOn : SystemEventKind::PcOff,
            'title' => $online ? 'pc on' : 'pc off',
            'occurred_at' => $now,
        ]);
    }

    /**
     * @param  array<string, string>  $errors
     * @return list<HermesJob>
     */
    private function hermesJobs(array &$errors): array
    {
        $jobs = $this->attempt('hermes-jobs', fn () => $this->probe->hermesJobs(), [], $errors);

        return $this->attempt(
            'hermes-runs',
            fn () => array_map(fn (HermesJob $job) => $job->withRecent($this->probe->hermesRuns($job->id)), $jobs),
            $jobs,
            $errors,
        );
    }

    /**
     * @param  list<ScheduleStatus>  $schedule
     * @param  list<ContainerStatus>  $containers
     * @param  list<HermesJob>  $jobs
     * @return list<SwitchStatus>
     */
    private function switchStatuses(array $schedule, array $containers, array $jobs): array
    {
        return array_map(fn (string $key) => match ($this->switches->kind($key)) {
            SwitchKind::Schedule => $this->scheduleSwitch($key, $schedule),
            SwitchKind::Hermes => $this->hermesSwitch($key, $jobs),
            SwitchKind::Stack => $this->stackSwitch($key, $containers),
        }, $this->switches->keys());
    }

    /**
     * @param  list<ScheduleStatus>  $schedule
     */
    private function scheduleSwitch(string $key, array $schedule): SwitchStatus
    {
        $definition = $this->switches->definition($key);
        $desired = $this->switches->isOn($key);
        $task = collect($schedule)->firstWhere('name', $definition['tasks'][0]);

        return new SwitchStatus(
            $key,
            SwitchKind::Schedule,
            $definition['label'],
            $definition['cadence'],
            SwitchKind::Schedule->runsOn(),
            $desired,
            $desired,
            false,
            $task?->lastFinishedAt,
            $task === null || $task->lastFinishedAt === null ? null : ! $task->lastFailed,
            $task?->nextDueAt,
            $task->recent ?? [],
            $this->switches->changedAt($key),
            $task === null ? 'not scheduled' : null,
        );
    }

    /**
     * @param  list<HermesJob>  $jobs
     */
    private function hermesSwitch(string $key, array $jobs): SwitchStatus
    {
        $definition = $this->switches->definition($key);
        $desired = $this->switches->isOn($key);
        $job = collect($jobs)->firstWhere('name', $definition['job']);
        $actual = $job?->running() ?? $desired;
        $latest = $job === null || $job->recent === [] ? null : $job->recent[array_key_last($job->recent)];

        return new SwitchStatus(
            $key,
            SwitchKind::Hermes,
            $definition['label'],
            $definition['cadence'],
            SwitchKind::Hermes->runsOn(),
            $desired,
            $actual,
            $job !== null && $desired !== $actual,
            $job?->lastRunAt ?? $latest?->at,
            $latest?->ok,
            $job?->nextRunAt,
            $job->recent ?? [],
            $this->switches->changedAt($key),
            $job === null ? SwitchStatus::NOT_INSTALLED : null,
        );
    }

    /**
     * @param  list<ContainerStatus>  $containers
     */
    private function stackSwitch(string $key, array $containers): SwitchStatus
    {
        $definition = $this->switches->definition($key);
        $desired = $this->switches->isOn($key);
        $members = $this->switches->stackMembers($containers, $definition['project']);
        $actual = $members === [] ? $desired : $this->switches->stackActual($members, $desired);

        return new SwitchStatus(
            $key,
            SwitchKind::Stack,
            $definition['label'],
            '',
            SwitchKind::Stack->runsOn(),
            $desired,
            $actual,
            $members !== [] && $desired !== $actual,
            null,
            null,
            null,
            [],
            $this->switches->changedAt($key),
            $members === [] ? SwitchStatus::NOT_INSTALLED : count($members).' containers',
        );
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function attempt(string $name, Closure $probe, mixed $fallback, array &$errors): mixed
    {
        try {
            return $probe();
        } catch (ProbeFailed $e) {
            $errors[$name] = $e->getMessage();

            return $fallback;
        }
    }

    /**
     * @return array{cert_days: int|null, ddns: array<string, string>|null, errors: array<string, string>}
     */
    private function slowProbes(): array
    {
        $ttl = now()->addMinutes((int) config('system.slow_probe_ttl_minutes', 10));

        return Cache::remember(self::SLOW_PROBES_KEY, $ttl, function (): array {
            $host = (string) config('system.cert.host');
            $errors = [];

            $days = $this->attempt('cert', fn () => $this->probe->certDaysLeft($host), null, $errors);
            $ddns = $this->attempt('ddns', fn () => $this->probe->dynamicDns($host), null, $errors);

            return [
                'cert_days' => $days,
                'ddns' => $ddns?->toArray(),
                'errors' => $errors,
            ];
        });
    }

    private function queueStatus(?int $backlog, CarbonImmutable $now): QueueStatus
    {
        $newest = FailedJob::query()->latest('id')->first();

        return new QueueStatus(
            $backlog,
            $this->overSince($backlog, $now),
            FailedJob::query()->count(),
            $newest?->displayName(),
        );
    }

    private function overSince(?int $backlog, CarbonImmutable $now): ?CarbonImmutable
    {
        $limit = (int) config('system.thresholds.queue_backlog', QueueStatus::BACKLOG_LIMIT);

        if ($backlog === null || $backlog <= $limit) {
            Cache::forget(self::OVER_SINCE_KEY);

            return null;
        }

        $since = Cache::get(self::OVER_SINCE_KEY);

        if ($since === null) {
            Cache::forever(self::OVER_SINCE_KEY, $now->toIso8601String());

            return $now;
        }

        return CarbonImmutable::parse($since);
    }

    /**
     * @return list<ScheduleStatus>
     */
    private function scheduleStatuses(CarbonImmutable $now): array
    {
        $statuses = [];

        foreach ($this->schedule->events() as $event) {
            $name = ScheduledTaskName::for($event);

            if ($name === 'system:collect') {
                continue;
            }

            $statuses[] = $this->scheduleStatus($event, $name, $now);
        }

        return $statuses;
    }

    private function scheduleStatus(Event $event, string $name, CarbonImmutable $now): ScheduleStatus
    {
        $cron = new CronExpression($event->expression);
        $next = CarbonImmutable::instance($cron->getNextRunDate($now->toDateTime(), 0, false, $event->timezone));
        $previous = CarbonImmutable::instance($cron->getPreviousRunDate($now->toDateTime(), 0, false, $event->timezone));
        $record = Cache::get(ScheduledTaskName::cacheKey($name), []);

        return new ScheduleStatus(
            $name,
            max(60, (int) $previous->diffInSeconds($next)),
            $this->firstSeen($name, $now),
            $next,
            isset($record['started_at']) ? CarbonImmutable::parse($record['started_at']) : null,
            isset($record['finished_at']) ? CarbonImmutable::parse($record['finished_at']) : null,
            $record['exit_code'] ?? null,
            (bool) ($record['failed'] ?? false),
            array_map(
                fn (array $run) => new RunTick(CarbonImmutable::parse($run['finished_at']), $run['exit_code'] === 0),
                $record['recent'] ?? [],
            ),
        );
    }

    private function firstSeen(string $name, CarbonImmutable $now): CarbonImmutable
    {
        $key = self::FIRST_SEEN_KEY.sha1($name);

        Cache::add($key, $now->toIso8601String());

        return CarbonImmutable::parse(Cache::get($key));
    }
}
