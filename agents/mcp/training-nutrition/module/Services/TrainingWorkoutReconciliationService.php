<?php

namespace App\Modules\TrainingNutrition\Services;

use App\Models\User;
use App\Modules\Oura\Models\OuraConnection;
use App\Modules\Oura\Models\OuraDocument;
use App\Modules\TrainingNutrition\Data\ActivityType;
use App\Modules\TrainingNutrition\Data\SessionReconcile;
use App\Modules\TrainingNutrition\Data\WorkoutLink;
use App\Modules\TrainingNutrition\Data\WorkoutUnlink;
use App\Modules\TrainingNutrition\Models\TrainingNutritionOperation;
use App\Modules\TrainingNutrition\Models\TrainingSession;
use App\Modules\TrainingNutrition\Models\TrainingWorkoutLink;
use App\Modules\TrainingNutrition\Resources\TrainingSessionResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TrainingWorkoutReconciliationService
{
    private const TYPES = [
        'running' => 'run',
        'run' => 'run',
        'strength_training' => 'strength',
        'weightlifting' => 'strength',
        'functional_fitness' => 'strength',
        'mixed' => 'mixed',
    ];

    public function __construct(private TrainingMutationService $mutations) {}

    public function connection(int $ownerId): ?OuraConnection
    {
        return OuraConnection::where('user_id', $ownerId)->first();
    }

    public function reconcile(SessionReconcile $input, array $payload, int $ownerId): array
    {
        return $this->mutations->mutate($ownerId, 'training_session.reconcile', $payload, fn (): array => $this->batch($ownerId, $input->limit));
    }

    public function batch(int $ownerId, int $limit = 50): array
    {
        $connection = $this->connection($ownerId);

        if (! $connection) {
            return ['status' => 'unavailable', 'processed' => 0, 'remaining' => null];
        }

        return DB::transaction(fn (): array => $this->processBatch($ownerId, $connection, $limit));
    }

    private function processBatch(int $ownerId, OuraConnection $connection, int $limit): array
    {
        User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
        $progress = TrainingNutritionOperation::firstOrCreate(
            ['user_id' => $ownerId, 'request_id' => 'reconcile-progress'],
            ['action' => 'reconcile_progress', 'fingerprint' => hash('sha256', 'reconcile-progress'),
                'result' => ['last_document_id' => 0, 'last_completed_at' => null]],
        );
        $cursor = $progress->result['last_document_id'] ?? 0;
        $documents = OuraDocument::where('oura_connection_id', $connection->id)
            ->where('dataset', 'workout')->where('id', '>', $cursor)
            ->orderBy('id')->limit($limit)->get();

        foreach ($documents as $document) {
            $this->reconcileDocument($ownerId, $connection, $document);
        }

        $lastId = $documents->last()?->id ?? $cursor;
        $remaining = OuraDocument::where('oura_connection_id', $connection->id)
            ->where('dataset', 'workout')->where('id', '>', $lastId)->count();
        $complete = $remaining === 0;
        $progress->result = [
            'last_document_id' => $complete ? 0 : $lastId,
            'last_completed_at' => $complete ? now()->toIso8601String() : ($progress->result['last_completed_at'] ?? null),
        ];
        $progress->save();

        return [
            'status' => 'ok',
            'processed' => $documents->count(),
            'remaining' => $remaining,
            'last_completed_at' => $progress->result['last_completed_at'],
            'unresolved' => TrainingWorkoutLink::where('user_id', $ownerId)->where('state', 'unresolved')->count(),
        ];
    }

    public function matchOwnerSession(TrainingSession $session): void
    {
        if ($session->provenance !== 'owner' || ! $this->interval($session)) {
            return;
        }

        $connection = $this->connection($session->user_id);
        if (! $connection) {
            return;
        }

        $from = $session->started_at->setTimezone(config('services.oura.timezone', 'UTC'))
            ->subDay()->toDateString();
        $through = $session->ended_at->setTimezone(config('services.oura.timezone', 'UTC'))
            ->addDay()->toDateString();
        $documents = OuraDocument::where('oura_connection_id', $connection->id)
            ->where('dataset', 'workout')->whereNull('source_deleted_at')
            ->whereBetween('source_day', [$from, $through])->limit(100)->get();

        foreach ($documents as $document) {
            $this->reconcileDocument($session->user_id, $connection, $document);
        }
    }

    private function reconcileDocument(int $ownerId, OuraConnection $connection, OuraDocument $document): void
    {
        $link = TrainingWorkoutLink::firstOrNew([
            'oura_connection_id' => $connection->id,
            'dataset' => 'workout',
            'source_id' => $document->source_id,
        ]);
        $link->user_id = $ownerId;
        $link->source_revision = $document->revision;
        $link->last_checked_at = now();

        if (in_array($link->state, ['excluded', 'manual'], true)) {
            $link->save();

            return;
        }

        $interval = $this->sourceInterval($document);
        if ($document->source_deleted_at || ! $document->payload || ! $interval) {
            $this->markSourceUnavailable($link);

            return;
        }

        $candidates = $this->candidates($ownerId, $document);

        if ($candidates->count() > 1) {
            $this->markSourceAmbiguous($link, $candidates->pluck('id')->all());

            return;
        }

        if ($candidates->count() === 1) {
            $this->linkOwnerSession($link, $candidates->first(), $interval);

            return;
        }

        $this->recordSourceOnlySession($link, $document, $interval);
    }

    private function markSourceUnavailable(TrainingWorkoutLink $link): void
    {
        $this->hideSourceShell($link);
        $link->state = 'unavailable';
        $link->session_id = null;
        $link->candidate_ids = [];
        $link->save();
    }

    /** @param list<int> $candidateIds */
    private function markSourceAmbiguous(TrainingWorkoutLink $link, array $candidateIds): void
    {
        $this->hideSourceShell($link);
        $link->state = 'unresolved';
        $link->session_id = null;
        $link->candidate_ids = $candidateIds;
        $link->save();
    }

    /** @param array{CarbonImmutable, CarbonImmutable} $interval */
    private function linkOwnerSession(TrainingWorkoutLink $link, TrainingSession $session, array $interval): void
    {
        if ($link->session_id && $link->session_id !== $session->id) {
            $shell = TrainingSession::forOwner($link->user_id)->find($link->session_id);
            if ($shell && $shell->provenance === 'oura' && $shell->merged_into_id === null) {
                $this->absorb($shell, $session);
            }
        }

        $link->session_id = $session->id;
        $link->state = 'linked';
        $link->method = 'automatic';
        $link->overlap = $this->overlap($interval, $this->interval($session));
        $link->candidate_ids = [];
        $link->save();
    }

    /** @param array{CarbonImmutable, CarbonImmutable} $interval */
    private function recordSourceOnlySession(TrainingWorkoutLink $link, OuraDocument $document, array $interval): void
    {
        if ($link->session_id) {
            $shell = TrainingSession::forOwner($link->user_id)->find($link->session_id);
            if ($shell && $shell->provenance === 'oura' && ! $shell->merged_into_id) {
                $shell->started_at = $interval[0];
                $shell->ended_at = $interval[1];
                $shell->session_day = $document->source_day?->toDateString();
                $shell->activity_type = $this->sourceType($document)?->value;
                $shell->state = 'completed';
                $shell->save();
                $link->state = 'linked';
                $link->save();

                return;
            }
        }

        $shell = TrainingSession::create([
            'user_id' => $link->user_id,
            'activity_type' => $this->sourceType($document)?->value,
            'started_at' => $interval[0],
            'ended_at' => $interval[1],
            'session_day' => $document->source_day?->toDateString(),
            'time_precision' => 'exact',
            'state' => 'completed',
            'provenance' => 'oura',
            'actual' => [],
            'activities' => [],
        ]);
        $link->session_id = $shell->id;
        $link->state = 'linked';
        $link->method = 'source_only';
        $link->candidate_ids = [];
        $link->save();
    }

    /** @return Collection<int, TrainingSession> */
    private function candidates(int $ownerId, OuraDocument $document): Collection
    {
        $interval = $this->sourceInterval($document);
        $type = $this->sourceType($document);

        return TrainingSession::where('user_id', $ownerId)->where('provenance', 'owner')
            ->whereNull('merged_into_id')->where('time_precision', 'exact')
            ->where('started_at', '<', $interval[1])->where('ended_at', '>', $interval[0])
            ->limit(101)->get()
            ->filter(fn (TrainingSession $session) => $this->compatible($type, $session)
                && $this->overlap($interval, $this->interval($session)) >= config('training_nutrition.match_overlap', 0.8));
    }

    private function absorb(TrainingSession $shell, TrainingSession $owner): void
    {
        if ($shell->user_id !== $owner->user_id || $shell->id === $owner->id
            || $shell->actual || $shell->subjective_notes || $shell->provenance !== 'oura') {
            throw new TrainingNutritionException('invalid_input', 'Source-only shell has independent work.');
        }

        TrainingWorkoutLink::where('user_id', $owner->user_id)->where('session_id', $shell->id)
            ->update(['session_id' => $owner->id]);
        $shell->merged_into_id = $owner->id;
        $shell->save();
    }

    private function hideSourceShell(TrainingWorkoutLink $link): void
    {
        if (! $link->session_id) {
            return;
        }

        if (TrainingWorkoutLink::where('user_id', $link->user_id)
            ->where('session_id', $link->session_id)
            ->where('id', '!=', $link->id)
            ->whereIn('state', ['linked', 'manual'])->exists()) {
            return;
        }

        $shell = TrainingSession::where('user_id', $link->user_id)->find($link->session_id);
        if ($shell && $shell->provenance === 'oura' && ! $shell->merged_into_id) {
            $shell->state = 'unavailable';
            $shell->save();
        }
    }

    private function sourceType(OuraDocument $document): ?ActivityType
    {
        return ActivityType::tryFrom(self::TYPES[strtolower((string) ($document->payload['activity'] ?? ''))] ?? '');
    }

    private function compatible(?ActivityType $sourceType, TrainingSession $session): bool
    {
        return $sourceType !== null && ($sourceType->value === $session->activity_type
            || ($session->activity_type === ActivityType::Mixed->value
                && in_array($sourceType->value, $session->activities ?? [], true)));
    }

    /** @return array{CarbonImmutable, CarbonImmutable}|null */
    private function sourceInterval(OuraDocument $document): ?array
    {
        $payload = $document->payload ?? [];
        if (! isset($payload['start_datetime'], $payload['end_datetime']) || ! $this->sourceType($document)) {
            return null;
        }

        try {
            $start = CarbonImmutable::parse($payload['start_datetime'])->utc();
            $end = CarbonImmutable::parse($payload['end_datetime'])->utc();
        } catch (\Throwable) {
            return null;
        }

        return $end->greaterThan($start) ? [$start, $end] : null;
    }

    /** @return array{CarbonImmutable, CarbonImmutable}|null */
    private function interval(TrainingSession $session): ?array
    {
        if ($session->time_precision !== 'exact' || ! $session->started_at || ! $session->ended_at
            || $session->ended_at->lessThanOrEqualTo($session->started_at)) {
            return null;
        }

        return [CarbonImmutable::instance($session->started_at), CarbonImmutable::instance($session->ended_at)];
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $a
     * @param  array{CarbonImmutable, CarbonImmutable}  $b
     */
    private function overlap(array $a, array $b): float
    {
        $seconds = max(0, min($a[1]->timestamp, $b[1]->timestamp) - max($a[0]->timestamp, $b[0]->timestamp));
        $shorter = min($a[1]->timestamp - $a[0]->timestamp, $b[1]->timestamp - $b[0]->timestamp);

        return $shorter > 0 ? $seconds / $shorter : 0;
    }

    public function manualLink(WorkoutLink $input, array $payload, int $ownerId): array
    {
        return $this->mutations->mutate($ownerId, 'training_session.link', $payload, fn (): array => $this->linkWorkout($input, $ownerId));
    }

    public function manualUnlink(WorkoutUnlink $input, array $payload, int $ownerId): array
    {
        return $this->mutations->mutate($ownerId, 'training_session.unlink', $payload, fn (): array => $this->unlinkWorkout($input, $ownerId));
    }

    private function ownedDocument(int $ownerId, WorkoutLink|WorkoutUnlink $data): OuraDocument
    {
        $connection = $this->connection($ownerId);
        if (! $connection || $connection->id !== $data->ouraConnectionId) {
            throw new TrainingNutritionException('not_found', 'Workout source not found.');
        }

        $document = OuraDocument::where('oura_connection_id', $connection->id)
            ->where('dataset', 'workout')->where('source_id', $data->sourceId)
            ->whereNull('source_deleted_at')->first();
        if (! $document) {
            throw new TrainingNutritionException('not_found', 'Workout source not found.');
        }

        return $document;
    }

    public function withSources(TrainingSession $session): array
    {
        $links = $session->workoutLinks()->whereIn('state', ['linked', 'manual'])->get();
        $sources = [];
        $intervals = [];
        foreach ($links as $link) {
            $document = OuraDocument::where('oura_connection_id', $link->oura_connection_id)
                ->where('dataset', $link->dataset)->where('source_id', $link->source_id)
                ->whereNull('source_deleted_at')->first();
            if (! $document || ! $document->payload) {
                continue;
            }
            $interval = $this->sourceInterval($document);
            if ($interval) {
                $intervals[] = [$interval[0]->timestamp, $interval[1]->timestamp];
            }
            $payload = $document->payload;
            $sources[] = [
                'connection_id' => $link->oura_connection_id,
                'dataset' => $link->dataset,
                'source_id' => $link->source_id,
                'revision' => $document->revision,
                'activity' => $payload['activity'] ?? null,
                'start_datetime' => $payload['start_datetime'] ?? null,
                'end_datetime' => $payload['end_datetime'] ?? null,
                'distance_km' => isset($payload['distance']) ? $payload['distance'] / 1000 : null,
                'calories_kcal' => $payload['calories'] ?? $payload['active_calories'] ?? null,
                'match_method' => $link->method,
                'match_overlap' => $link->overlap,
            ];
        }

        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $union = 0;
        $overlaps = false;
        $end = null;
        foreach ($intervals as [$start, $stop]) {
            if ($end !== null && $start < $end) {
                $overlaps = true;
            }
            $union += max(0, $stop - max($start, $end ?? $start));
            $end = max($end ?? $stop, $stop);
        }
        $distanceKnown = count($sources) > 0 && count(array_filter($sources, fn ($source) => $source['distance_km'] !== null)) === count($sources);
        $caloriesKnown = count($sources) > 0 && count(array_filter($sources, fn ($source) => $source['calories_kcal'] !== null)) === count($sources);

        return TrainingSessionResource::make($session)->resolve() + [
            'sources' => $sources,
            'source_aggregate' => [
                'known_duration_seconds' => $intervals ? $union : null,
                'duration_complete' => count($intervals) === count($sources) && count($sources) > 0,
                'distance_km' => ! $overlaps && $distanceKnown ? array_sum(array_column($sources, 'distance_km')) : null,
                'calories_kcal' => ! $overlaps && $caloriesKnown ? array_sum(array_column($sources, 'calories_kcal')) : null,
                'aggregate_complete' => ! $overlaps && $distanceKnown && $caloriesKnown,
                'overlapping_sources' => $overlaps,
            ],
        ];
    }

    private function linkWorkout(WorkoutLink $data, int $ownerId): array
    {
        $session = app(TrainingSessionService::class)->canonical($data->sessionId, $ownerId);
        if ($session->version !== $data->expectedVersion) {
            throw new TrainingNutritionException('version_conflict', 'Record version changed.');
        }
        $document = $this->ownedDocument($ownerId, $data);
        $link = TrainingWorkoutLink::firstOrNew([
            'oura_connection_id' => $data->ouraConnectionId,
            'dataset' => 'workout', 'source_id' => $document->source_id,
        ]);
        if ($link->session_id && $link->session_id !== $session->id) {
            $prior = TrainingSession::where('user_id', $ownerId)->find($link->session_id);
            if ($prior && $prior->provenance === 'oura' && ! $prior->merged_into_id) {
                $this->absorb($prior, $session);
            }
        }
        $link->fill([
            'user_id' => $ownerId, 'session_id' => $session->id,
            'state' => 'manual', 'method' => 'manual', 'candidate_ids' => [],
            'source_revision' => $document->revision, 'last_checked_at' => now(),
        ])->save();
        $session->version++;
        $session->save();

        return ['session' => $this->withSources($session->fresh())];
    }

    private function unlinkWorkout(WorkoutUnlink $data, int $ownerId): array
    {
        $session = app(TrainingSessionService::class)->canonical($data->sessionId, $ownerId);
        if ($session->version !== $data->expectedVersion) {
            throw new TrainingNutritionException('version_conflict', 'Record version changed.');
        }
        $link = TrainingWorkoutLink::where('user_id', $ownerId)
            ->where('oura_connection_id', $data->ouraConnectionId)
            ->where('dataset', 'workout')->where('source_id', $data->sourceId)
            ->where('session_id', $session->id)->first();
        if (! $link) {
            throw new TrainingNutritionException('not_found', 'Link not found.');
        }
        $this->hideSourceShell($link);
        $link->update(['state' => 'excluded', 'session_id' => null, 'method' => 'manual_unlink']);
        $session->version++;
        $session->save();

        return ['session' => $this->withSources($session->fresh()), 'suppressed_source_id' => $data->sourceId];
    }
}
