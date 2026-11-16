<?php

namespace App\Mcp\Tools;

use App\Http\Requests\TrainingNutrition\TrainingSessionRequest;
use App\Models\User;
use App\Modules\TrainingNutrition\Data\ActivityType;
use App\Modules\TrainingNutrition\Data\PageQuery;
use App\Modules\TrainingNutrition\Data\SessionAmend;
use App\Modules\TrainingNutrition\Data\SessionComplete;
use App\Modules\TrainingNutrition\Data\SessionExercise;
use App\Modules\TrainingNutrition\Data\SessionGet;
use App\Modules\TrainingNutrition\Data\SessionLog;
use App\Modules\TrainingNutrition\Data\SessionReconcile;
use App\Modules\TrainingNutrition\Data\SessionStart;
use App\Modules\TrainingNutrition\Data\SessionTimePrecision;
use App\Modules\TrainingNutrition\Data\WorkoutLink;
use App\Modules\TrainingNutrition\Data\WorkoutSet;
use App\Modules\TrainingNutrition\Data\WorkoutUnlink;
use App\Modules\TrainingNutrition\Services\TrainingSessionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Record and inspect one performed workout. start snapshots an optional plan slot. log stores reported sets/reps/load. complete without as_prescribed leaves unreported work unknown; with as_prescribed it copies that named snapshot plus exceptions. Oura links use stable source identity, and linked evidence counts once. Actions: start, log, amend, complete, get, history, link, unlink, reconcile.')]
class TrainingSessionTool extends TrainingNutritionTool
{
    protected string $requestClass = TrainingSessionRequest::class;

    protected string $name = 'training_session';

    public function __construct(private TrainingSessionService $sessions) {}

    protected function run(array $input, User $owner): array
    {
        $data = match ($input['action']) {
            'start' => new SessionStart(
                $input['plan_id'] ?? null,
                $input['slot_id'] ?? null,
                ActivityType::from($input['activity_type']),
                $input['activities'] ?? [],
                $input['location'] ?? null,
                $input['started_at'] ?? null,
                $input['ended_at'] ?? null,
                SessionTimePrecision::from($input['time_precision']),
                $input['subjective_notes'] ?? null,
            ),
            'log' => new SessionLog(
                $input['session_id'],
                $input['expected_version'],
                $input['exercise_id'],
                array_map(WorkoutSet::fromValidated(...), $input['sets']),
            ),
            'amend' => new SessionAmend(
                $input['session_id'],
                $input['expected_version'],
                array_keys($input),
                $input['started_at'] ?? null,
                $input['ended_at'] ?? null,
                isset($input['time_precision']) ? SessionTimePrecision::from($input['time_precision']) : null,
                isset($input['activity_type']) ? ActivityType::from($input['activity_type']) : null,
                $input['activities'] ?? null,
                $input['location'] ?? null,
                $input['subjective_notes'] ?? null,
                isset($input['actual']) ? array_map(SessionExercise::fromValidated(...), $input['actual']) : null,
            ),
            'complete' => new SessionComplete(
                $input['session_id'],
                $input['expected_version'],
                $input['ended_at'] ?? null,
                $input['as_prescribed'] ?? false,
                array_map(SessionExercise::fromValidated(...), $input['exceptions'] ?? []),
                $input['subjective_notes'] ?? null,
                array_key_exists('subjective_notes', $input),
            ),
            'get' => new SessionGet($input['session_id']),
            'history' => new PageQuery($input['cursor'] ?? null, $input['limit'] ?? 50),
            'link' => new WorkoutLink(
                $input['session_id'],
                $input['expected_version'],
                $input['oura_connection_id'],
                $input['source_id'],
            ),
            'unlink' => new WorkoutUnlink(
                $input['session_id'],
                $input['expected_version'],
                $input['oura_connection_id'],
                $input['source_id'],
            ),
            'reconcile' => new SessionReconcile($input['limit'] ?? config('training_nutrition.reconcile_batch', 50)),
        };

        return $this->sessions->run($data, $input, $owner->id);
    }

    public function schema(JsonSchema $schema): array
    {
        $set = $schema->object([
            'reps' => $schema->integer(),
            'load_kg' => $schema->number()->nullable(),
            'duration_minutes' => $schema->number()->nullable(),
            'distance_km' => $schema->number()->nullable(),
        ])->withoutAdditionalProperties();

        return [
            'action' => $schema->string()->enum([
                'start', 'log', 'amend', 'complete', 'get', 'history', 'link', 'unlink', 'reconcile',
            ])->required(),
            'request_id' => $schema->string(),
            'session_id' => $schema->integer(),
            'expected_version' => $schema->integer(),
            'plan_id' => $schema->integer(),
            'slot_id' => $schema->string(),
            'activity_type' => $schema->string()->enum(['strength', 'run', 'mixed', 'other']),
            'activities' => $schema->array()->items($schema->string()->enum(['strength', 'run'])),
            'location' => $schema->string()->nullable(),
            'started_at' => $schema->string()->nullable(),
            'ended_at' => $schema->string()->nullable(),
            'time_precision' => $schema->string()->enum(['exact', 'date']),
            'subjective_notes' => $schema->string()->nullable(),
            'exercise_id' => $schema->integer(),
            'sets' => $schema->array()->items($set),
            'actual' => $schema->array()->items($schema->object([
                'exercise_id' => $schema->integer(),
                'sets' => $schema->array()->items($set),
            ])->withoutAdditionalProperties()),
            'as_prescribed' => $schema->boolean(),
            'exceptions' => $schema->array()->items($schema->object([
                'exercise_id' => $schema->integer(),
                'sets' => $schema->array()->items($set),
            ])->withoutAdditionalProperties()),
            'source_id' => $schema->string()->description('Oura workout source ID for manual link or unlink'),
            'oura_connection_id' => $schema->integer(),
            'cursor' => $schema->string(),
            'limit' => $schema->integer(),
        ];
    }
}
