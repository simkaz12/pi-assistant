<?php

namespace App\Modules\System\Data;

use App\Modules\Pi\Data\PiStats;
use Carbon\CarbonImmutable;

final readonly class SystemSnapshot
{
    public function __construct(
        public CarbonImmutable $collectedAt,
        public PiStats $host,
        /** @var array<string, string> */
        public array $units,
        /** @var list<ContainerStatus> */
        public array $containers,
        /** @var list<ScheduleStatus> */
        public array $schedule,
        public QueueStatus $queue,
        public ?int $certDays,
        public ?DynamicDnsStatus $ddns,
        /** @var list<WireguardPeer> */
        public array $peers,
        public bool $pcOnline,
        public bool $wolRelayOnline,
        /** @var array<string, string> */
        public array $probeErrors,
        /** @var list<SwitchStatus> */
        public array $switches = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            CarbonImmutable::parse($data['collected_at']),
            PiStats::fromArray($data['host']),
            $data['units'],
            array_map(ContainerStatus::fromArray(...), $data['containers']),
            array_map(ScheduleStatus::fromArray(...), $data['schedule']),
            QueueStatus::fromArray($data['queue']),
            $data['cert_days'],
            $data['ddns'] === null ? null : DynamicDnsStatus::fromArray($data['ddns']),
            array_map(WireguardPeer::fromArray(...), $data['peers']),
            $data['pc_online'],
            $data['wol_relay_online'],
            $data['probe_errors'],
            array_map(SwitchStatus::fromArray(...), $data['switches'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'collected_at' => $this->collectedAt->toIso8601String(),
            'host' => $this->host->toArray(),
            'units' => $this->units,
            'containers' => array_map(fn (ContainerStatus $c) => $c->toArray(), $this->containers),
            'schedule' => array_map(fn (ScheduleStatus $s) => $s->toArray(), $this->schedule),
            'queue' => $this->queue->toArray(),
            'cert_days' => $this->certDays,
            'ddns' => $this->ddns?->toArray(),
            'peers' => array_map(fn (WireguardPeer $p) => $p->toArray(), $this->peers),
            'pc_online' => $this->pcOnline,
            'wol_relay_online' => $this->wolRelayOnline,
            'probe_errors' => $this->probeErrors,
            'switches' => array_map(fn (SwitchStatus $switch) => $switch->toArray(), $this->switches),
        ];
    }
}
