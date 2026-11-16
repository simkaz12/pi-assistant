<?php

namespace App\Modules\System\Jobs;

use App\Modules\System\Enums\SwitchKind;
use App\Modules\System\Enums\SystemEventKind;
use App\Modules\System\Exceptions\ProbeFailed;
use App\Modules\System\Models\SystemEvent;
use App\Modules\System\Models\SystemSwitch;
use App\Modules\System\Probes\HostProbe;
use App\Modules\System\Services\Switches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class ApplySwitch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $key,
        public bool $on,
    ) {}

    public function handle(Switches $switches, HostProbe $probe): void
    {
        $definition = $switches->definition($this->key);
        $kind = $switches->kind($this->key);

        $failure = match ($kind) {
            SwitchKind::Hermes => $this->applyHermes($probe, $definition['job']),
            SwitchKind::Stack => $this->applyStack($definition['dir']),
            SwitchKind::Schedule => null,
        };

        if ($failure === null) {
            return;
        }

        $this->revert($switches, $probe, $kind, $definition, $failure);
    }

    private function applyHermes(HostProbe $probe, string $jobName): ?string
    {
        $id = null;

        try {
            foreach ($probe->hermesJobs() as $job) {
                $id = $job->name === $jobName ? $job->id : $id;
            }
        } catch (ProbeFailed $e) {
            return $e->getMessage();
        }

        if ($id === null) {
            return "job not found: {$jobName}";
        }

        return $this->run([config('system.hermes_bin'), 'cron', $this->on ? 'resume' : 'pause', $id], 60);
    }

    private function applyStack(string $directory): ?string
    {
        return $this->run(['docker', 'compose', $this->on ? 'start' : 'stop'], 280, $directory);
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, int $timeout, ?string $directory = null): ?string
    {
        $pending = Process::timeout($timeout);

        try {
            $result = $directory === null ? $pending->run($command) : $pending->path($directory)->run($command);
        } catch (ProcessTimedOutException $e) {
            return $this->tail($e->getMessage());
        }

        return $result->failed() ? $this->tail($result->errorOutput() ?: $result->output()) : null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function revert(
        Switches $switches,
        HostProbe $probe,
        SwitchKind $kind,
        array $definition,
        string $stderr,
    ): void {
        $actual = $this->actual($switches, $probe, $kind, $definition);

        SystemSwitch::query()
            ->where('key', $this->key)
            ->where('on', $this->on)
            ->update(['on' => $actual ?? ! $this->on, 'changed_at' => now()]);

        SystemEvent::query()->create([
            'kind' => SystemEventKind::Notice,
            'title' => $definition['label'].' '.($this->on ? 'start' : 'stop').' failed',
            'body' => $stderr,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function actual(Switches $switches, HostProbe $probe, SwitchKind $kind, array $definition): ?bool
    {
        try {
            if ($kind === SwitchKind::Hermes) {
                $matches = array_filter($probe->hermesJobs(), fn ($job) => $job->name === $definition['job']);

                return $matches === [] ? null : array_values($matches)[0]->running();
            }

            $members = $switches->stackMembers($probe->containers(), $definition['project']);

            return $members === [] ? null : $switches->stackActual($members, $this->on);
        } catch (ProbeFailed) {
            return null;
        }
    }

    private function tail(string $text): string
    {
        return Str::of(trim($text))->substr(-500)->toString();
    }
}
