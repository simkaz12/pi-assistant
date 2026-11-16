<?php

namespace App\Modules\System\Data;

use App\Modules\System\Enums\SwitchKind;
use Carbon\CarbonImmutable;

final readonly class SwitchStatus
{
    public const NOT_INSTALLED = 'not installed';

    public function __construct(
        public string $key,
        public SwitchKind $kind,
        public string $label,
        public string $cadence,
        public string $runsOn,
        public bool $desiredOn,
        public bool $actualOn,
        public bool $pending,
        public ?CarbonImmutable $lastRunAt,
        public ?bool $lastOk,
        public ?CarbonImmutable $nextRunAt,
        /** @var list<RunTick> */
        public array $recent,
        public ?CarbonImmutable $changedAt,
        public ?string $note,
    ) {}

    public function withDesired(bool $desiredOn, ?CarbonImmutable $changedAt): self
    {
        $scheduled = $this->kind === SwitchKind::Schedule;
        $actualOn = $scheduled ? $desiredOn : $this->actualOn;

        return new self(
            $this->key,
            $this->kind,
            $this->label,
            $this->cadence,
            $this->runsOn,
            $desiredOn,
            $actualOn,
            ! $scheduled && $this->note !== self::NOT_INSTALLED && $desiredOn !== $actualOn,
            $this->lastRunAt,
            $this->lastOk,
            $this->nextRunAt,
            $this->recent,
            $changedAt,
            $this->note,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            SwitchKind::from($data['kind']),
            $data['label'],
            $data['cadence'],
            $data['runs_on'],
            $data['desired_on'],
            $data['actual_on'],
            $data['pending'],
            self::instant($data['last_run_at']),
            $data['last_ok'],
            self::instant($data['next_run_at']),
            RunTick::collect($data['recent']),
            self::instant($data['changed_at']),
            $data['note'],
        );
    }

    private static function instant(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'cadence' => $this->cadence,
            'runs_on' => $this->runsOn,
            'desired_on' => $this->desiredOn,
            'actual_on' => $this->actualOn,
            'pending' => $this->pending,
            'last_run_at' => $this->lastRunAt?->toIso8601String(),
            'last_ok' => $this->lastOk,
            'next_run_at' => $this->nextRunAt?->toIso8601String(),
            'recent' => array_map(fn (RunTick $tick) => $tick->toArray(), $this->recent),
            'changed_at' => $this->changedAt?->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
