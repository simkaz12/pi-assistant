<?php

namespace App\Modules\Oura\Services;

use App\Modules\Oura\Jobs\SyncOuraDataset;
use App\Modules\Oura\Models\OuraConnection;
use App\Modules\Oura\Models\OuraSyncState;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OuraConnectionService
{
    public const SCOPES = [
        'daily',
        'heartrate',
        'tag',
        'workout',
        'session',
        'spo2',
        'ring_configuration',
        'stress',
        'heart_health',
    ];

    public function owner(): OuraConnection
    {
        $userId = (int) config('services.oura.user_id');

        if (! $userId || ! DB::table('users')->where('id', $userId)->exists()) {
            throw new RuntimeException('Oura owner is not configured.');
        }

        return OuraConnection::firstOrCreate(['user_id' => $userId]);
    }

    public function authorize(): array
    {
        if (! config('services.oura.client_id') || ! config('services.oura.client_secret')
            || ! config('services.oura.redirect_uri')) {
            throw new RuntimeException('Oura OAuth is not configured.');
        }

        $connection = $this->owner();
        $state = bin2hex(random_bytes(32));
        $connection->update([
            'state_hash' => hash('sha256', $state),
            'state_expires_at' => now()->addMinutes(10),
        ]);

        return [
            'url' => 'https://developer.ouraring.com/authorize?'.http_build_query([
                'response_type' => 'code',
                'client_id' => config('services.oura.client_id'),
                'redirect_uri' => config('services.oura.redirect_uri'),
                'scope' => implode(' ', self::SCOPES),
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986),
            'expires_at' => $connection->state_expires_at->toIso8601String(),
        ];
    }

    public function callback(
        string $state,
        string $code,
        string $grantedScopes,
        OuraClient $client,
        OuraDatasetRegistry $registry,
    ): void {
        $connection = $this->consumeState($state);

        try {
            $credentials = $client->exchange($code);
        } catch (\Throwable $e) {
            $connection->update([
                'error_code' => $e instanceof OuraOAuthExchangeException
                    ? $e->errorCode
                    : 'authorization_failed',
            ]);
            throw $e;
        }

        DB::transaction(function () use ($connection, $credentials, $registry, $grantedScopes): void {
            $locked = OuraConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->generation !== $connection->generation) {
                throw new RuntimeException('Oura authorization was cancelled.');
            }
            $credentials['scopes'] ??= self::normalizeScopes($grantedScopes);
            $locked->update($credentials + [
                'status' => 'connected',
                'generation' => $locked->generation + 1,
                'refresh_started_at' => null,
                'error_code' => null,
            ]);

            foreach ($registry->names() as $dataset) {
                OuraSyncState::updateOrCreate(
                    ['oura_connection_id' => $locked->id, 'dataset' => $dataset],
                    [
                        'status' => 'pending',
                        'history_floor' => config('services.oura.history_floor'),
                        'window_start' => config('services.oura.history_floor'),
                        'window_end' => null,
                        'reconcile_cursor' => null,
                        'next_token' => null,
                        'error_code' => null,
                    ],
                );
            }
        });

        foreach ($registry->names() as $dataset) {
            SyncOuraDataset::dispatch($connection->id, $dataset, $connection->fresh()->generation);
        }
    }

    public function deny(string $state): void
    {
        $connection = $this->consumeState($state);
        $connection->update(['error_code' => 'access_denied']);
    }

    public static function normalizeScopes(string $scopes): array
    {
        $granted = preg_split('/\s+/', trim($scopes), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = array_map(
            fn (string $scope) => str_starts_with($scope, 'extapi:') ? substr($scope, 7) : $scope,
            $granted,
        );

        return array_values(array_intersect(self::SCOPES, $normalized));
    }

    public function status(): array
    {
        $connection = $this->owner();
        OuraConnection::query()->whereKey($connection->id)
            ->where('status', 'refreshing')
            ->where('refresh_started_at', '<=', now()->subSeconds(30))
            ->update([
                'status' => 'reconnect_required',
                'refresh_started_at' => null,
                'error_code' => 'refresh_uncertain',
            ]);
        $connection->refresh();
        $states = OuraSyncState::query()->where('oura_connection_id', $connection->id)->get();

        return [
            'status' => $connection->status,
            'granted_scopes' => $connection->scopes ?? [],
            'last_successful_sync_at' => $connection->last_successful_sync_at?->toIso8601String(),
            'error_code' => $connection->error_code,
            'refresh_started_at' => $connection->refresh_started_at?->toIso8601String(),
            'recent_exclusions' => DB::table('oura_exclusions')
                ->where('oura_connection_id', $connection->id)
                ->orderByDesc('id')->limit(10)->get(['from_day', 'through_day'])->all(),
            'datasets' => $states->map(fn (OuraSyncState $state) => [
                'dataset' => $state->dataset,
                'status' => $state->status,
                'window_start' => $state->window_start?->toDateString(),
                'history_floor' => $state->history_floor?->toDateString(),
                'reconcile_cursor' => $state->reconcile_cursor?->toDateString(),
                'last_successful_sync_at' => $state->last_successful_sync_at?->toIso8601String(),
                'lag_seconds' => $state->last_successful_sync_at
                    ? (int) $state->last_successful_sync_at->diffInSeconds(now())
                    : null,
                'record_count' => $state->record_count,
                'error_code' => $state->error_code,
            ])->all(),
        ];
    }

    private function consumeState(string $state): OuraConnection
    {
        return DB::transaction(function () use ($state): OuraConnection {
            $locked = OuraConnection::query()->where('user_id', config('services.oura.user_id'))
                ->lockForUpdate()->firstOrFail();

            if (! $locked->state_hash || ! $locked->state_expires_at?->isFuture()
                || ! hash_equals($locked->state_hash, hash('sha256', $state))) {
                throw new RuntimeException('Invalid or expired Oura authorization.');
            }

            $locked->update(['state_hash' => null, 'state_expires_at' => null]);

            return $locked;
        });
    }

    public function disconnect(OuraClient $client): array
    {
        $connection = $this->owner();

        DB::transaction(function () use ($connection): void {
            $locked = OuraConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $locked->update([
                'status' => 'disconnected',
                'generation' => $locked->generation + 1,
                'access_token' => null,
                'refresh_token' => null,
                'expires_at' => null,
                'refresh_started_at' => null,
                'state_hash' => null,
                'state_expires_at' => null,
                'error_code' => null,
            ]);
        });

        return ['status' => 'disconnected'];
    }
}
