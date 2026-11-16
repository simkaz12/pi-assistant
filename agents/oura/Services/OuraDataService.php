<?php

namespace App\Modules\Oura\Services;

use App\Modules\Oura\Models\OuraConnection;
use App\Modules\Oura\Models\OuraDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OuraDataService
{
    public function __construct(
        private OuraConnectionService $connections,
        private OuraClient $client,
        private OuraDatasetRegistry $registry,
    ) {}

    public function run(array $input): array
    {
        return match ($input['action'] ?? null) {
            'export' => $this->export($input),
            'delete_preview' => $this->preview($input),
            'delete_confirm' => $this->confirm($input),
            default => throw new InvalidArgumentException('action must be export, delete_preview, or delete_confirm.'),
        };
    }

    private function export(array $input): array
    {
        $connection = $this->connections->owner();
        [$from, $through] = $this->range($input);
        $dataset = $input['dataset'] ?? null;
        if ($dataset !== null) {
            if (! is_string($dataset)) {
                throw new InvalidArgumentException('Invalid dataset.');
            }
            $this->registry->get($dataset);
        }

        $limit = $this->limit($input);
        $cursor = $this->cursor($input);
        $query = OuraDocument::query()
            ->where('oura_connection_id', $connection->id)
            ->where('id', '>', $cursor)
            ->orderBy('id')->limit($limit + 1);

        if (($input['all'] ?? false) !== true) {
            $query->whereBetween('source_day', [$from, $through]);
        }

        if ($dataset) {
            $query->where('dataset', $dataset);
        }

        $records = $query->get();
        $page = $records->take($limit);

        return [
            'action' => 'export',
            'from' => $from,
            'through' => $through,
            'records' => $page->map(fn (OuraDocument $document) => [
                'dataset' => $document->dataset,
                'source_id' => $document->source_id,
                'source_day' => $document->source_day?->toDateString(),
                'source_at' => $document->source_at?->toIso8601String(),
                'payload' => $document->payload,
                'provenance' => $document->provenance,
                'fetched_at' => $document->fetched_at?->toIso8601String(),
                'source_deleted_at' => $document->source_deleted_at?->toIso8601String(),
            ])->all(),
            'next_cursor' => $records->count() > $limit ? (string) $page->last()->id : null,
        ];
    }

    private function preview(array $input): array
    {
        $connection = $this->connections->owner();
        [$from, $through] = $this->range($input);
        $whole = ($input['all'] ?? false) === true;
        $query = OuraDocument::query()->where('oura_connection_id', $connection->id);
        if (! $whole) {
            $query->whereBetween('source_day', [$from, $through]);
        }
        $count = $query->count();
        $token = bin2hex(random_bytes(32));

        DB::table('oura_deletion_intents')->insert([
            'oura_connection_id' => $connection->id,
            'token_hash' => hash('sha256', $token),
            'from_day' => $whole ? null : $from,
            'through_day' => $whole ? null : $through,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'action' => 'delete_preview',
            'from' => $from,
            'through' => $through,
            'whole_history' => $whole,
            'cutoff_day' => $whole ? $this->deletionCutoff($connection) : $through,
            'record_count' => $count,
            'confirmation_token' => $token,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ];
    }

    private function confirm(array $input): array
    {
        $token = $input['confirmation_token'] ?? null;
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new InvalidArgumentException('Valid confirmation_token required.');
        }

        $connection = $this->connections->owner();
        $result = DB::transaction(function () use ($connection, $token): array {
            $locked = OuraConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $intent = DB::table('oura_deletion_intents')
                ->where('oura_connection_id', $connection->id)
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()->first();

            if (! $intent || CarbonImmutable::parse($intent->expires_at)->isPast()) {
                throw new InvalidArgumentException('Confirmation expired or already used.');
            }

            $whole = $intent->from_day === null;
            $from = $whole ? '1000-01-01' : $intent->from_day;
            $through = $whole ? $this->deletionCutoff($locked) : $intent->through_day;
            $locked->update(['generation' => $locked->generation + 1]);

            if ($whole) {
                OuraDocument::query()->where('oura_connection_id', $connection->id)
                    ->whereNull('source_day')->lazyById(500)->each(function (OuraDocument $document): void {
                        DB::table('oura_excluded_sources')->insertOrIgnore([
                            'oura_connection_id' => $document->oura_connection_id,
                            'dataset' => $document->dataset,
                            'source_id' => $document->source_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });
            }

            $query = OuraDocument::query()->where('oura_connection_id', $connection->id);
            if (! $whole) {
                $query->whereBetween('source_day', [$from, $through]);
            }
            $deleted = $query->delete();

            DB::table('oura_exclusions')->insert([
                'oura_connection_id' => $connection->id,
                'from_day' => $from,
                'through_day' => $through,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('oura_deletion_intents')->where('oura_connection_id', $connection->id)->delete();

            foreach (DB::table('oura_sync_states')->where('oura_connection_id', $connection->id)->get() as $state) {
                $remaining = OuraDocument::query()->where('oura_connection_id', $connection->id)
                    ->where('dataset', $state->dataset)->count();
                DB::table('oura_sync_states')->where('id', $state->id)
                    ->update(['record_count' => $remaining]);
            }

            return ['deleted_records' => $deleted, 'whole_history' => $whole, 'cutoff_day' => $through];
        });

        if ($result['whole_history']) {
            $this->connections->disconnect($this->client);
        }

        return $result + ['status' => $result['whole_history'] ? 'disconnected' : 'connected'];
    }

    private function range(array $input): array
    {
        if (($input['all'] ?? false) === true) {
            return ['1000-01-01', '9999-12-31'];
        }

        $from = $input['from'] ?? null;
        $through = $input['through'] ?? null;
        if (! is_string($from) || ! is_string($through)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $through)) {
            throw new InvalidArgumentException('Explicit from and through dates required, or all=true.');
        }

        try {
            $first = CarbonImmutable::createFromFormat('!Y-m-d', $from);
            $last = CarbonImmutable::createFromFormat('!Y-m-d', $through);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }

        if ($first->toDateString() !== $from || $last->toDateString() !== $through || $first->isAfter($last)) {
            throw new InvalidArgumentException('Invalid date range.');
        }

        return [$from, $through];
    }

    private function deletionCutoff(OuraConnection $connection): string
    {
        $today = CarbonImmutable::now(config('services.oura.timezone'))->toDateString();
        $latest = OuraDocument::query()->where('oura_connection_id', $connection->id)
            ->whereNotNull('source_day')->max('source_day');

        return $latest && $latest > $today ? $latest : $today;
    }

    private function limit(array $input): int
    {
        $limit = $input['limit'] ?? 50;
        if (filter_var($limit, FILTER_VALIDATE_INT) === false || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be 1..100.');
        }

        return (int) $limit;
    }

    private function cursor(array $input): int
    {
        $cursor = $input['cursor'] ?? 0;
        if (filter_var($cursor, FILTER_VALIDATE_INT) === false || $cursor < 0) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        return (int) $cursor;
    }
}
