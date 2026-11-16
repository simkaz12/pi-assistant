<?php

namespace App\Modules\System\Probes;

use App\Modules\System\Data\ContainerStatus;
use App\Modules\System\Data\DynamicDnsStatus;
use App\Modules\System\Data\HermesJob;
use App\Modules\System\Data\RunTick;
use App\Modules\System\Data\WireguardPeer;
use App\Modules\System\Exceptions\ProbeFailed;

interface HostProbe
{
    /**
     * @param  list<string>  $units
     * @return array<string, string>
     *
     * @throws ProbeFailed
     */
    public function unitStates(array $units): array;

    /**
     * @return list<ContainerStatus>
     *
     * @throws ProbeFailed
     */
    public function containers(): array;

    /**
     * @return list<WireguardPeer>
     *
     * @throws ProbeFailed
     */
    public function wireguardPeers(): array;

    /**
     * @throws ProbeFailed
     */
    public function certDaysLeft(string $host): int;

    /**
     * @throws ProbeFailed
     */
    public function dynamicDns(string $host): DynamicDnsStatus;

    /**
     * @throws ProbeFailed
     */
    public function queueBacklog(): int;

    /**
     * @return list<HermesJob>
     *
     * @throws ProbeFailed
     */
    public function hermesJobs(): array;

    /**
     * @return list<RunTick>
     *
     * @throws ProbeFailed
     */
    public function hermesRuns(string $jobId): array;
}
