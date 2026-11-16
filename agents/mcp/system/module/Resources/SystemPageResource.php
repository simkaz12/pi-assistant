<?php

namespace App\Modules\System\Resources;

use App\Modules\System\Data\PcPeriod;
use App\Modules\System\Data\Problem;
use App\Modules\System\Data\RunTick;
use App\Modules\System\Data\SwitchStatus;
use App\Modules\System\Data\SystemSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemPageResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  list<Problem>  $problems
     * @param  list<SwitchStatus>  $switches
     * @param  list<PcPeriod>  $periods
     */
    public function __construct(
        private ?SystemSnapshot $snapshot,
        private array $problems,
        private array $switches,
        private ?CarbonImmutable $pcSince,
        private array $periods,
        private CarbonImmutable $now,
    ) {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'collectedAt' => $this->snapshot?->collectedAt->toIso8601String(),
            'problems' => array_map(fn (Problem $problem) => $problem->toArray(), $this->problems),
            'pc' => [
                'online' => $this->snapshot?->pcOnline,
                'since' => $this->pcSince?->toIso8601String(),
                'periods' => array_map(fn (PcPeriod $period) => $period->toArray(), $this->periods),
            ],
            'switches' => array_map(fn (SwitchStatus $switch) => $this->switch($switch), $this->switches),
            'now' => $this->now->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function switch(SwitchStatus $switch): array
    {
        return [
            'key' => $switch->key,
            'kind' => $switch->kind->value,
            'label' => $switch->label,
            'cadence' => $switch->cadence,
            'runsOn' => $switch->runsOn,
            'desiredOn' => $switch->desiredOn,
            'actualOn' => $switch->actualOn,
            'pending' => $switch->pending,
            'lastRunAt' => $switch->lastRunAt?->toIso8601String(),
            'lastOk' => $switch->lastOk,
            'nextRunAt' => $switch->nextRunAt?->toIso8601String(),
            'recent' => array_map(fn (RunTick $tick) => $tick->toArray(), $switch->recent),
            'changedAt' => $switch->changedAt?->toIso8601String(),
            'note' => $switch->note,
        ];
    }
}
