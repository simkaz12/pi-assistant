<?php

namespace App\Modules\System\Services;

use App\Modules\Pi\Data\PiStorageStats;
use App\Modules\System\Data\ContainerStatus;
use App\Modules\System\Data\Finding;
use App\Modules\System\Data\QueueStatus;
use App\Modules\System\Data\ScheduleStatus;
use App\Modules\System\Data\SystemSnapshot;
use App\Modules\System\Enums\Severity;
use Carbon\CarbonImmutable;

class HealthRules
{
    public function __construct(
        private Switches $switches,
    ) {}

    /**
     * @return list<Finding>
     */
    public function evaluate(SystemSnapshot $snapshot, CarbonImmutable $now): array
    {
        return array_values([
            ...$this->disks($snapshot),
            ...$this->temperature($snapshot),
            ...$this->cert($snapshot),
            ...$this->units($snapshot),
            ...$this->containers($snapshot),
            ...$this->schedule($snapshot, $now),
            ...$this->failedJobs($snapshot->queue),
            ...$this->backlog($snapshot->queue, $now),
            ...$this->ddns($snapshot),
            ...$this->relay($snapshot),
            ...$this->probes($snapshot),
        ]);
    }

    /**
     * @return list<Finding>
     */
    private function disks(SystemSnapshot $snapshot): array
    {
        $findings = [];

        foreach ($snapshot->host->storage as $disk) {
            $severity = $this->atLeast($disk->percent, 'disk');

            if ($severity !== null) {
                $findings[] = $this->diskFinding($disk, $severity);
            }
        }

        return $findings;
    }

    private function diskFinding(PiStorageStats $disk, Severity $severity): Finding
    {
        return new Finding(
            "disk:{$disk->mount}",
            $severity,
            "Disk {$disk->mount} {$disk->percent}%",
            "{$disk->usedGb} of {$disk->totalGb} GB used, {$disk->freeGb} GB free",
        );
    }

    /**
     * @return list<Finding>
     */
    private function temperature(SystemSnapshot $snapshot): array
    {
        $temp = $snapshot->host->temp;
        $severity = $temp === null ? null : $this->atLeast($temp, 'temp');

        if ($severity === null) {
            return [];
        }

        return [new Finding('temp', $severity, "Temperature {$temp} °C", 'CPU temperature is high')];
    }

    /**
     * @return list<Finding>
     */
    private function cert(SystemSnapshot $snapshot): array
    {
        $days = $snapshot->certDays;

        if ($days === null) {
            return [];
        }

        $severity = match (true) {
            $days < config('system.thresholds.cert_days.critical') => Severity::Critical,
            $days < config('system.thresholds.cert_days.warning') => Severity::Warning,
            default => null,
        };

        if ($severity === null) {
            return [];
        }

        $host = config('system.cert.host');

        return [new Finding('cert', $severity, "TLS certificate expires in {$days} days", "Certificate for {$host}")];
    }

    /**
     * @return list<Finding>
     */
    private function units(SystemSnapshot $snapshot): array
    {
        $findings = [];

        foreach ($snapshot->units as $name => $state) {
            if ($state === 'active') {
                continue;
            }

            $findings[] = new Finding("unit:{$name}", Severity::Critical, "{$name} is not active", "systemd state: {$state}");
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function containers(SystemSnapshot $snapshot): array
    {
        $critical = config('system.critical_containers', []);
        $quiet = $this->switches->offProjects();
        $findings = [];

        foreach ($snapshot->containers as $container) {
            $problem = $this->containerProblem($container);

            if ($problem === null || $container->isOneShot() || in_array($container->project, $quiet, true)) {
                continue;
            }

            $findings[] = new Finding(
                "container:{$container->name}",
                in_array($container->name, $critical, true) ? Severity::Critical : Severity::Warning,
                "Container {$container->name} is {$problem}",
                "state: {$container->state}".($container->health ? ", health: {$container->health}" : ''),
            );
        }

        return $findings;
    }

    private function containerProblem(ContainerStatus $container): ?string
    {
        if ($container->state !== 'running') {
            return $container->state;
        }

        return $container->health === 'unhealthy' ? 'unhealthy' : null;
    }

    /**
     * @return list<Finding>
     */
    private function schedule(SystemSnapshot $snapshot, CarbonImmutable $now): array
    {
        $findings = [];

        foreach ($snapshot->schedule as $task) {
            $key = $this->switches->keyForTask($task->name);

            if ($key !== null && ! $this->switches->isOn($key)) {
                continue;
            }

            $finding = $this->scheduleFinding($task, $now, $key === null ? null : $this->switches->changedAt($key));

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function scheduleFinding(ScheduleStatus $task, CarbonImmutable $now, ?CarbonImmutable $switchedOnAt): ?Finding
    {
        $key = "schedule:{$task->name}";

        if ($task->lastFailed) {
            return new Finding(
                $key,
                Severity::Critical,
                "Schedule {$task->name} failed",
                'last run exited with code '.($task->lastExitCode ?? 'unknown'),
            );
        }

        if ($task->isMissed($now, $switchedOnAt)) {
            return new Finding(
                $key,
                Severity::Critical,
                "Schedule {$task->name} did not run",
                'no finished run since '.$task->missedReference($switchedOnAt)->toIso8601String(),
            );
        }

        return null;
    }

    /**
     * @return list<Finding>
     */
    private function failedJobs(QueueStatus $queue): array
    {
        if ($queue->failedJobs <= 0) {
            return [];
        }

        $newest = $queue->newestFailedClass ?? 'unknown';

        return [new Finding(
            'failed-jobs',
            Severity::Critical,
            "{$queue->failedJobs} failed jobs (newest: {$newest})",
            'failed_jobs is not empty',
        )];
    }

    /**
     * @return list<Finding>
     */
    private function backlog(QueueStatus $queue, CarbonImmutable $now): array
    {
        $limit = (int) config('system.thresholds.queue_backlog', QueueStatus::BACKLOG_LIMIT);

        if ($queue->backlog === null || $queue->backlog <= $limit || ! $queue->backlogSustained($now)) {
            return [];
        }

        return [new Finding(
            'queue:default',
            Severity::Warning,
            "Queue backlog {$queue->backlog}",
            'default queue has been over '.$limit.' jobs for '.QueueStatus::BACKLOG_MINUTES.' minutes',
        )];
    }

    /**
     * @return list<Finding>
     */
    private function ddns(SystemSnapshot $snapshot): array
    {
        $status = $snapshot->ddns;

        if ($status === null || $status->matches()) {
            return [];
        }

        return [new Finding(
            'ddns',
            Severity::Warning,
            'dynamic DNS record does not match the public IP',
            "record {$status->resolvedIp}, public {$status->publicIp}",
        )];
    }

    /**
     * @return list<Finding>
     */
    private function relay(SystemSnapshot $snapshot): array
    {
        if ($snapshot->wolRelayOnline) {
            return [];
        }

        return [new Finding('wol-relay', Severity::Critical, 'WoL relay is not responding', 'no /health response')];
    }

    /**
     * @return list<Finding>
     */
    private function probes(SystemSnapshot $snapshot): array
    {
        $findings = [];

        foreach ($snapshot->probeErrors as $name => $message) {
            $findings[] = new Finding("probe:{$name}", Severity::Warning, "Probe {$name} cannot run", $message);
        }

        return $findings;
    }

    private function atLeast(int|float $value, string $rule): ?Severity
    {
        return match (true) {
            $value >= config("system.thresholds.{$rule}.critical") => Severity::Critical,
            $value >= config("system.thresholds.{$rule}.warning") => Severity::Warning,
            default => null,
        };
    }
}
