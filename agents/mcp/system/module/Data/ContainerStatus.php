<?php

namespace App\Modules\System\Data;

final readonly class ContainerStatus
{
    public function __construct(
        public string $name,
        public string $state,
        public ?string $health,
        public ?string $project = null,
        public ?string $restartPolicy = null,
        public ?int $exitCode = null,
    ) {}

    public function isOneShot(): bool
    {
        return $this->state === 'exited'
            && $this->restartPolicy === 'no'
            && $this->exitCode === 0;
    }

    /**
     * @param  array{name: string, state: string, health: string|null, project?: string|null, restart_policy?: string|null, exit_code?: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['state'],
            $data['health'],
            $data['project'] ?? null,
            $data['restart_policy'] ?? null,
            $data['exit_code'] ?? null,
        );
    }

    /**
     * @return array{name: string, state: string, health: string|null, project: string|null, restart_policy: string|null, exit_code: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->state,
            'health' => $this->health,
            'project' => $this->project,
            'restart_policy' => $this->restartPolicy,
            'exit_code' => $this->exitCode,
        ];
    }
}
