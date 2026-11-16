<?php

namespace App\Modules\Oura\Commands;

use App\Modules\Oura\Jobs\SyncOuraDataset;
use App\Modules\Oura\Models\OuraConnection;
use App\Modules\Oura\Models\OuraSyncState;
use App\Modules\Oura\Services\OuraDatasetRegistry;
use App\Modules\Oura\Services\OuraSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncOura extends Command
{
    protected $signature = 'oura:sync {mode : history, recent, older, or prune}';

    protected $description = 'Run Oura collection and maintenance';

    public function handle(
        OuraDatasetRegistry $registry,
        OuraSyncService $sync,
    ): int {
        $mode = $this->argument('mode');
        if (! in_array($mode, ['history', 'recent', 'older', 'prune'], true)) {
            $this->error('Invalid mode.');

            return self::FAILURE;
        }

        if ($mode === 'prune') {
            DB::table('oura_deletion_intents')->where('expires_at', '<', now())->delete();

            return self::SUCCESS;
        }

        foreach (OuraConnection::where('status', 'connected')->get() as $connection) {
            foreach ($registry->names() as $dataset) {
                $today = CarbonImmutable::today(config('services.oura.timezone'));
                if ($mode === 'history') {
                    $state = OuraSyncState::where('oura_connection_id', $connection->id)
                        ->where('dataset', $dataset)->first();
                    if ($state && in_array($state->status, ['pending', 'importing', 'partial'], true)) {
                        SyncOuraDataset::dispatch($connection->id, $dataset, $connection->generation);
                    }

                    continue;
                }

                if ($mode === 'older') {
                    $state = OuraSyncState::where('oura_connection_id', $connection->id)
                        ->where('dataset', $dataset)->first();
                    if (! $state || $state->status !== 'complete') {
                        continue;
                    }

                    $floor = CarbonImmutable::parse(
                        $state->history_floor ?? config('services.oura.history_floor'),
                    );
                    $cutoff = $today->subDays(7);
                    $start = $state->reconcile_cursor
                        ? CarbonImmutable::parse($state->reconcile_cursor)
                        : $floor;
                    if ($start->isAfter($cutoff)) {
                        $start = $floor;
                    }
                    $end = $start->addDays(max(1, min(31, (int) config('services.oura.window_days'))) - 1)
                        ->min($cutoff);

                    if ($sync->reconcileWindow($connection, $dataset, $start, $end)) {
                        $state->update(['reconcile_cursor' => $end->addDay()->toDateString()]);
                    }

                    continue;
                }

                $sync->reconcileWindow(
                    $connection,
                    $dataset,
                    $today->subDays(6),
                    $today,
                );
            }
        }

        return self::SUCCESS;
    }
}
