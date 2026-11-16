<?php

namespace App\Modules\TrainingNutrition\Services;

use App\Modules\TrainingNutrition\Data\PageQuery;
use App\Modules\TrainingNutrition\Data\SessionAmend;
use App\Modules\TrainingNutrition\Data\SessionComplete;
use App\Modules\TrainingNutrition\Data\SessionGet;
use App\Modules\TrainingNutrition\Data\SessionLog;
use App\Modules\TrainingNutrition\Data\SessionReconcile;
use App\Modules\TrainingNutrition\Data\SessionStart;
use App\Modules\TrainingNutrition\Data\SessionTimePrecision;
use App\Modules\TrainingNutrition\Data\WorkoutLink;
use App\Modules\TrainingNutrition\Data\WorkoutSet;
use App\Modules\TrainingNutrition\Data\WorkoutUnlink;
use App\Modules\TrainingNutrition\Models\TrainingExercise;
use App\Modules\TrainingNutrition\Models\TrainingPlan;
use App\Modules\TrainingNutrition\Models\TrainingProfile;
use App\Modules\TrainingNutrition\Models\TrainingSession;
use Carbon\CarbonImmutable;

class TrainingSessionService
{
    public function __construct(
        private TrainingMutationService $mutations,
        private TrainingWorkoutReconciliationService $reconciliation,
    ) {}

    public function run(
        SessionStart|SessionLog|SessionAmend|SessionComplete|SessionGet|PageQuery|WorkoutLink|WorkoutUnlink|SessionReconcile $input,
        array $payload,
        int $ownerId,
    ): array {
        return match ($input::class) {
            SessionStart::class => $this->mutations->mutate(
                $ownerId, 'training_session.start', $payload,
                fn (): array => $this->createSession($input, $ownerId),
            ),
            SessionLog::class => $this->mutations->mutate(
                $ownerId, 'training_session.log', $payload,
                fn (): array => $this->appendExerciseSets($input, $ownerId),
            ),
            SessionAmend::class => $this->mutations->mutate(
                $ownerId, 'training_session.amend', $payload,
                fn (): array => $this->amendSession($input, $ownerId),
            ),
            SessionComplete::class => $this->mutations->mutate(
                $ownerId, 'training_session.complete', $payload,
                fn (): array => $this->completeSession($input, $ownerId),
            ),
            SessionGet::class => $this->get($input, $ownerId),
            PageQuery::class => $this->history($input, $ownerId),
            WorkoutLink::class => $this->reconciliation->manualLink($input, $payload, $ownerId),
            WorkoutUnlink::class => $this->reconciliation->manualUnlink($input, $payload, $ownerId),
            SessionReconcile::class => $this->reconciliation->reconcile($input, $payload, $ownerId),
        };
    }

    public function canonical(int $id, int $ownerId): TrainingSession
    {
        $seen = [];

        while (true) {
            if (isset($seen[$id])) {
                throw new TrainingNutritionException('invalid_input', 'Session alias cycle.');
            }
            $seen[$id] = true;
            $session = TrainingSession::forOwner($ownerId)->findOrFail($id);

            if (! $session->merged_into_id) {
                return $session;
            }

            $id = $session->merged_into_id;
        }
    }

    private function get(SessionGet $input, int $ownerId): array
    {
        $session = $this->canonical($input->sessionId, $ownerId);

        return ['session' => $this->detail($session), 'canonical_id' => $session->id];
    }

    private function history(PageQuery $input, int $ownerId): array
    {
        $limit = $input->limit;
        $afterId = $this->mutations->readPageCursor($input->cursor, $ownerId,
            ['action' => 'history', 'kind' => 'session']);
        $records = TrainingSession::where('user_id', $ownerId)
            ->whereNull('merged_into_id')->where('state', '!=', 'unavailable')
            ->where('id', '>', $afterId)
            ->orderBy('id')->limit($limit + 1)->get();

        return [
            'sessions' => $records->take($limit)->map($this->detail(...))->all(),
            'next_cursor' => $records->count() > $limit
                ? $this->mutations->pageCursor($ownerId, ['action' => 'history', 'kind' => 'session'],
                    $records[$limit - 1]->id)
                : null,
        ];
    }

    public function detail(TrainingSession $session): array
    {
        return $this->reconciliation->withSources($session);
    }

    private function sessionDay(string $time, SessionTimePrecision $precision, int $ownerId): string
    {
        if ($precision === SessionTimePrecision::Date) {
            return substr($time, 0, 10);
        }

        $zone = TrainingProfile::where('user_id', $ownerId)->value('timezone') ?? 'UTC';

        return CarbonImmutable::parse($time)->setTimezone($zone)->toDateString();
    }

    private function requireOffset(?string $time, SessionTimePrecision $precision): void
    {
        if ($time && $precision === SessionTimePrecision::Exact
            && ! preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/', $time)) {
            throw new TrainingNutritionException('invalid_input', 'Exact timestamps need an offset.');
        }
    }

    private function createSession(SessionStart $data, int $ownerId): array
    {
        $this->requireOffset($data->startedAt, $data->timePrecision);
        $this->requireOffset($data->endedAt, $data->timePrecision);
        $prescription = null;

        if ($data->planId !== null) {
            $plan = TrainingPlan::forOwner($ownerId)->findOrFail($data->planId);
            $prescription = collect($plan->slots)->firstWhere('slot_id', $data->slotId);

            if (! $prescription) {
                throw new TrainingNutritionException('not_found', 'Plan slot not found.');
            }
        }

        $session = TrainingSession::create([
            'user_id' => $ownerId,
            'plan_id' => $data->planId,
            'slot_id' => $data->slotId,
            'prescription' => $prescription,
            'actual' => [],
            'activities' => $data->activities,
            'activity_type' => $data->activityType->value,
            'location' => $data->location,
            'started_at' => $data->timePrecision === SessionTimePrecision::Exact ? $data->startedAt : null,
            'ended_at' => $data->timePrecision === SessionTimePrecision::Exact ? $data->endedAt : null,
            'session_day' => $data->startedAt !== null
                ? $this->sessionDay($data->startedAt, $data->timePrecision, $ownerId) : null,
            'time_precision' => $data->timePrecision->value,
            'subjective_notes' => $data->subjectiveNotes,
            'state' => 'in_progress',
            'provenance' => 'owner',
        ]);

        $this->reconciliation->matchOwnerSession($session);

        return ['session' => $this->detail($session->fresh())];
    }

    private function appendExerciseSets(SessionLog $data, int $ownerId): array
    {
        $session = $this->canonical($data->sessionId, $ownerId);
        if ($session->version !== $data->expectedVersion) {
            throw new TrainingNutritionException('version_conflict', 'Record version changed.');
        }
        $exercise = TrainingExercise::forOwner($ownerId)->findOrFail($data->exerciseId);
        $actual = $session->actual ?? [];
        $actual[] = [
            'exercise_id' => $exercise->id,
            'exercise_snapshot' => $exercise->only('id', 'name', 'measures'),
            'sets' => array_map(fn (WorkoutSet $set): array => $set->toArray(), $data->sets),
            'provenance' => 'owner_reported',
        ];
        $session->actual = $actual;
        $session->version++;
        $session->save();

        return ['session' => $this->detail($session)];
    }

    private function amendSession(SessionAmend $data, int $ownerId): array
    {
        $session = $this->canonical($data->sessionId, $ownerId);
        if ($session->version !== $data->expectedVersion) {
            throw new TrainingNutritionException('version_conflict', 'Record version changed.');
        }
        $precision = $data->timePrecision ?? SessionTimePrecision::from($session->time_precision);
        $this->requireOffset($data->startedAt, $precision);
        $this->requireOffset($data->endedAt, $precision);

        if ($data->actual !== null) {
            $actual = [];
            foreach ($data->actual as $entry) {
                $exercise = TrainingExercise::forOwner($ownerId)->findOrFail($entry->exerciseId);
                $actual[] = [
                    'exercise_id' => $exercise->id,
                    'exercise_snapshot' => $exercise->only('id', 'name', 'measures'),
                    'sets' => $entry->setsToArray(),
                    'provenance' => 'owner_correction',
                ];
            }
            $session->actual = $actual;
        }

        foreach ([
            'time_precision' => $data->timePrecision?->value,
            'activity_type' => $data->activityType?->value,
            'activities' => $data->activities,
            'location' => $data->location,
            'subjective_notes' => $data->subjectiveNotes,
        ] as $field => $value) {
            if ($data->has($field)) {
                $session->$field = $value;
            }
        }

        if ($data->has('started_at')) {
            $session->session_day = $data->startedAt
                ? $this->sessionDay($data->startedAt, SessionTimePrecision::from($session->time_precision), $ownerId)
                : null;
        }

        if (SessionTimePrecision::from($session->time_precision) === SessionTimePrecision::Date) {
            $session->started_at = null;
            $session->ended_at = null;
        } else {
            if ($data->has('started_at')) {
                $session->started_at = $data->startedAt;
            }
            if ($data->has('ended_at')) {
                $session->ended_at = $data->endedAt;
            }
        }

        $session->version++;
        $session->save();
        $this->reconciliation->matchOwnerSession($session);

        return ['session' => $this->detail($session->fresh())];
    }

    private function completeSession(SessionComplete $data, int $ownerId): array
    {
        $session = $this->canonical($data->sessionId, $ownerId);
        if ($session->version !== $data->expectedVersion) {
            throw new TrainingNutritionException('version_conflict', 'Record version changed.');
        }
        $this->requireOffset($data->endedAt, SessionTimePrecision::from($session->time_precision));

        if ($data->asPrescribed) {
            if (! $session->prescription) {
                throw new TrainingNutritionException('invalid_input', 'No prescription snapshot to confirm.');
            }

            $actual = [];
            $exceptions = collect($data->exceptions)->keyBy('exerciseId');
            foreach ($session->prescription['exercises'] ?? [] as $exercise) {
                $override = $exceptions->get($exercise['exercise_id']);
                $sets = $override?->setsToArray() ?? array_fill(0, $exercise['sets'], array_filter([
                    'reps' => $exercise['reps'] ?? null,
                    'load_kg' => $exercise['load_kg'] ?? null,
                    'duration_minutes' => $exercise['duration_minutes'] ?? null,
                ], fn ($value) => $value !== null));
                $actual[] = [
                    'exercise_id' => $exercise['exercise_id'],
                    'exercise_snapshot' => $exercise['catalogue_snapshot'],
                    'sets' => $sets,
                    'provenance' => $override ? 'owner_exception' : 'as_prescribed',
                ];
            }
            $session->actual = $actual;
        }

        $session->state = 'completed';
        if (SessionTimePrecision::from($session->time_precision) === SessionTimePrecision::Exact
            && $data->endedAt !== null) {
            $session->ended_at = $data->endedAt;
        }
        if ($data->hasSubjectiveNotes) {
            $session->subjective_notes = $data->subjectiveNotes;
        }
        $session->version++;
        $session->save();
        $this->reconciliation->matchOwnerSession($session);

        return ['session' => $this->detail($session->fresh())];
    }
}
