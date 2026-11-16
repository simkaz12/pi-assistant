<?php

namespace App\Modules\Oura\Jobs;

use App\Modules\Oura\Models\OuraSyncState;
use App\Modules\Oura\Services\OuraRetryLater;
use App\Modules\Oura\Services\OuraSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncOuraDataset implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(
        public int $connectionId,
        public string $dataset,
        public int $generation,
    ) {}

    public function handle(OuraSyncService $sync): void
    {
        try {
            $more = $sync->backfillPage($this->connectionId, $this->dataset, $this->generation);
            if ($more) {
                self::dispatch($this->connectionId, $this->dataset, $this->generation);
            }
        } catch (OuraRetryLater $e) {
            $this->release($e->seconds);
        } catch (Throwable $e) {
            OuraSyncState::query()->where('oura_connection_id', $this->connectionId)
                ->where('dataset', $this->dataset)
                ->update(['status' => 'partial', 'error_code' => 'sync_failed']);
            throw $e;
        }
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 1200, 1800];
    }
}
