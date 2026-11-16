<?php

namespace App\Modules\Oura\Services;

use App\Modules\Oura\Models\OuraConnection;
use App\Modules\Oura\Models\OuraDocument;
use App\Modules\Oura\Models\OuraSyncState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OuraSyncService
{
    public function __construct(private OuraClient $client, private OuraDatasetRegistry $registry) {}

    public function backfillPage(int $connectionId, string $dataset, int $generation): bool
    {
        $connection = OuraConnection::findOrFail($connectionId);
        if ($connection->status !== 'connected' || $connection->generation !== $generation) {
            return false;
        }

        $state = OuraSyncState::firstOrCreate(
            ['oura_connection_id' => $connectionId, 'dataset' => $dataset],
            [
                'history_floor' => config('services.oura.history_floor'),
                'window_start' => config('services.oura.history_floor'),
            ],
        );

        if ($state->status === 'complete' || $state->status === 'access_denied') {
            return false;
        }

        $start = CarbonImmutable::parse($state->window_start ?? config('services.oura.history_floor'))->startOfDay();
        $days = max(1, min(31, (int) config('services.oura.window_days', 7)));
        $end = $start->addDays($days - 1)->min(CarbonImmutable::today());
        if ($start->isAfter(CarbonImmutable::today())) {
            $state->update(['status' => 'complete', 'last_successful_sync_at' => now()]);

            return false;
        }

        $fetchStartedAt = now();
        $result = $this->fetchPage($connection, $dataset, $start, $end, $state->next_token);
        if ($result['status'] === 'access_denied') {
            $state->update(['status' => 'access_denied', 'error_code' => 'provider_access_denied']);

            return false;
        }

        DB::transaction(function () use ($state, $connection, $dataset, $result, $start, $end, $fetchStartedAt): void {
            $locked = OuraConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->status !== 'connected' || $locked->generation !== $connection->generation) {
                return;
            }

            $checkpoint = OuraSyncState::query()->lockForUpdate()->findOrFail($state->id);
            if ($checkpoint->window_start?->toDateString() !== $state->window_start?->toDateString()
                || $checkpoint->next_token !== $state->next_token) {
                return;
            }

            $fetchedAt = $fetchStartedAt;
            foreach ($result['data'] as $item) {
                $this->store($locked, $dataset, $item, 'backfill', $fetchedAt);
            }

            $next = $result['next_token'];
            $checkpoint->update([
                'status' => $next ? 'importing' : ($end->isToday() ? 'complete' : 'importing'),
                'window_start' => $next ? $start->toDateString() : $end->addDay()->toDateString(),
                'window_end' => $end->toDateString(),
                'next_token' => $next,
                'last_attempt_at' => $fetchedAt,
                'last_successful_sync_at' => $fetchedAt,
                'error_code' => null,
                'record_count' => $checkpoint->record_count + count($result['data']),
            ]);
            $locked->update(['last_successful_sync_at' => $fetchedAt]);
        });

        return $state->fresh()->status === 'importing';
    }

    public function reconcileWindow(OuraConnection $connection, string $dataset, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($connection->status !== 'connected') {
            return false;
        }

        $windowStartedAt = now();
        $runId = (string) Str::uuid();
        $token = null;
        do {
            $fetchStartedAt = now();
            $result = $this->fetchPage($connection, $dataset, $start, $end, $token);
            if ($result['status'] === 'access_denied') {
                OuraSyncState::updateOrCreate(
                    ['oura_connection_id' => $connection->id, 'dataset' => $dataset],
                    ['status' => 'access_denied', 'error_code' => 'provider_access_denied'],
                );

                return false;
            }

            DB::transaction(function () use ($connection, $dataset, $result, $fetchStartedAt, $runId): void {
                $locked = OuraConnection::query()->lockForUpdate()->findOrFail($connection->id);
                if ($locked->status !== 'connected' || $locked->generation !== $connection->generation) {
                    return;
                }

                $fetchedAt = $fetchStartedAt;
                foreach ($result['data'] as $item) {
                    $this->store($locked, $dataset, $item, 'reconcile', $fetchedAt, $runId);
                }

                OuraSyncState::updateOrCreate(
                    ['oura_connection_id' => $connection->id, 'dataset' => $dataset],
                    ['last_successful_sync_at' => $fetchedAt, 'error_code' => null],
                );
                $locked->update(['last_successful_sync_at' => $fetchedAt]);
            });
            $token = $result['next_token'];
        } while ($token);

        return DB::transaction(function () use ($connection, $dataset, $start, $end, $runId, $windowStartedAt): bool {
            $locked = OuraConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->status !== 'connected' || $locked->generation !== $connection->generation) {
                return false;
            }

            DB::table('oura_documents')
                ->where('oura_connection_id', $connection->id)
                ->where('dataset', $dataset)
                ->whereBetween('source_day', [$start->toDateString(), $end->toDateString()])
                ->whereNull('source_deleted_at')
                ->where(function ($query) use ($runId): void {
                    $query->whereNull('reconcile_marker')->orWhere('reconcile_marker', '!=', $runId);
                })
                ->where(function ($query) use ($windowStartedAt): void {
                    $query->whereNull('fetched_at')->orWhere('fetched_at', '<=', $windowStartedAt);
                })
                ->update([
                    'payload' => null,
                    'provenance' => 'reconcile_delete',
                    'source_deleted_at' => now(),
                    'fetched_at' => now(),
                    'revision' => DB::raw('revision + 1'),
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    private function fetchPage(
        OuraConnection $connection,
        string $dataset,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $token,
    ): array {
        $settings = $this->registry->get($dataset);
        $timezone = config('services.oura.timezone');
        $startTime = CarbonImmutable::parse($start->toDateString(), $timezone)->startOfDay()->utc();
        $endTime = CarbonImmutable::parse($end->toDateString(), $timezone)->addDay()->startOfDay()->utc();
        $params = $settings['time'] === 'datetime'
            ? ['start_datetime' => $startTime->toIso8601String(), 'end_datetime' => $endTime->toIso8601String()]
            : ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()];

        if ($token) {
            $params['next_token'] = $token;
        }

        $response = $this->client->request($connection, 'GET', $this->registry->collectionPath($dataset), $params);

        if ($response->status() === 403) {
            return ['status' => 'access_denied', 'data' => [], 'next_token' => null];
        }

        $this->retryIfNeeded($response);

        if (! $response->successful()) {
            throw new RuntimeException('Oura collection fetch failed: '.$response->status());
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('Oura collection response lacked data.');
        }

        return [
            'status' => 'ok',
            'data' => $data,
            'next_token' => $response->json('next_token'),
        ];
    }

    private function retryIfNeeded(Response $response): void
    {
        if ($response->status() !== 429 && ! $response->serverError()) {
            return;
        }

        $delay = $response->header('Retry-After');
        $seconds = ctype_digit((string) $delay) ? (int) $delay : 60;
        throw new OuraRetryLater(min(3600, max(1, $seconds)));
    }

    private function store(
        OuraConnection $connection,
        string $dataset,
        array $payload,
        string $provenance,
        \DateTimeInterface $fetchedAt,
        ?string $reconcileMarker = null,
    ): void {
        $sourceId = (string) ($payload['id'] ?? $payload['timestamp_unix'] ?? $payload['timestamp'] ?? '');
        if ($sourceId === '') {
            throw new RuntimeException('Oura document had no stable identity.');
        }

        $timestamp = $payload['timestamp'] ?? $payload['start_datetime']
            ?? $payload['start_time'] ?? $payload['set_up_at'] ?? null;
        $day = $payload['day'] ?? $payload['start_day'] ?? ($timestamp
            ? CarbonImmutable::parse($timestamp)->setTimezone(config('services.oura.timezone'))->toDateString()
            : null);

        $excluded = $day
            ? DB::table('oura_exclusions')->where('oura_connection_id', $connection->id)
                ->whereDate('from_day', '<=', $day)->whereDate('through_day', '>=', $day)->exists()
            : DB::table('oura_excluded_sources')->where('oura_connection_id', $connection->id)
                ->where('dataset', $dataset)->where('source_id', $sourceId)->exists();

        if ($excluded) {
            return;
        }

        $document = OuraDocument::firstOrNew([
            'oura_connection_id' => $connection->id,
            'dataset' => $dataset,
            'source_id' => $sourceId,
        ]);

        if ($document->source_deleted_at || ($document->fetched_at && $document->fetched_at > $fetchedAt)) {
            return;
        }

        $document->fill([
            'source_day' => $day,
            'source_at' => $timestamp ? CarbonImmutable::parse($timestamp) : null,
            'payload' => $payload,
            'provenance' => $provenance,
            'fetched_at' => $fetchedAt,
            'revision' => ($document->revision ?? 0) + 1,
            'reconcile_marker' => $reconcileMarker,
        ])->save();
    }
}
