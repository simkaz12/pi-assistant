<?php

namespace App\Modules\System\Services;

use App\Modules\System\Data\ContainerStatus;
use App\Modules\System\Data\SwitchStatus;
use App\Modules\System\Enums\SwitchKind;
use App\Modules\System\Jobs\ApplySwitch;
use App\Modules\System\Models\SystemSwitch;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class Switches
{
    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(config('system.switches', []));
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $key): array
    {
        $definition = config("system.switches.{$key}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown switch {$key}");
        }

        return $definition;
    }

    public function kind(string $key): SwitchKind
    {
        return SwitchKind::from($this->definition($key)['kind']);
    }

    public function isOn(string $key): bool
    {
        $this->definition($key);

        return SystemSwitch::query()->find($key)?->on ?? true;
    }

    public function set(string $key, bool $on): void
    {
        $this->definition($key);

        SystemSwitch::query()->updateOrCreate(['key' => $key], ['on' => $on, 'changed_at' => now()]);

        if ($this->kind($key)->appliedByJob()) {
            ApplySwitch::dispatch($key, $on);
        }
    }

    public function changedAt(string $key): ?CarbonImmutable
    {
        $this->definition($key);

        $changedAt = SystemSwitch::query()->find($key)?->changed_at;

        return $changedAt === null ? null : CarbonImmutable::instance($changedAt);
    }

    public function keyForTask(string $taskName): ?string
    {
        foreach ($this->keys() as $key) {
            if (in_array($taskName, $this->definition($key)['tasks'] ?? [], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        $stored = SystemSwitch::query()->pluck('on', 'key');
        $all = [];

        foreach ($this->keys() as $key) {
            $all[$key] = (bool) ($stored[$key] ?? true);
        }

        return $all;
    }

    /**
     * @param  list<SwitchStatus>  $statuses
     * @return list<SwitchStatus>
     */
    public function withLiveDesired(array $statuses): array
    {
        $rows = SystemSwitch::query()->get()->keyBy('key');

        return array_map(function (SwitchStatus $status) use ($rows): SwitchStatus {
            $row = $rows[$status->key] ?? null;
            $changedAt = $row?->changed_at;

            return $status->withDesired(
                $row?->on ?? true,
                $changedAt === null ? null : CarbonImmutable::instance($changedAt),
            );
        }, $statuses);
    }

    /**
     * @return list<string>
     */
    public function offProjects(): array
    {
        $projects = [];

        foreach ($this->all() as $key => $on) {
            if (! $on && $this->kind($key) === SwitchKind::Stack) {
                $projects[] = $this->definition($key)['project'];
            }
        }

        return $projects;
    }

    /**
     * @param  list<ContainerStatus>  $containers
     * @return list<ContainerStatus>
     */
    public function stackMembers(array $containers, string $project): array
    {
        return array_values(array_filter(
            $containers,
            fn (ContainerStatus $container) => $container->project === $project && ! $container->isOneShot(),
        ));
    }

    /**
     * @param  list<ContainerStatus>  $members
     */
    public function stackActual(array $members, bool $desiredOn): bool
    {
        $running = count(array_filter($members, fn (ContainerStatus $container) => $container->state === 'running'));

        return $desiredOn ? $members !== [] && $running === count($members) : $running > 0;
    }
}
