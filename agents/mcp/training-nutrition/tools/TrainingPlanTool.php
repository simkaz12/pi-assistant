<?php

namespace App\Mcp\Tools;

use App\Http\Requests\TrainingNutrition\TrainingPlanRequest;
use App\Models\User;
use App\Modules\TrainingNutrition\Data\PageQuery;
use App\Modules\TrainingNutrition\Data\PlanAccept;
use App\Modules\TrainingNutrition\Data\PlanDirection;
use App\Modules\TrainingNutrition\Data\PlanEvidence;
use App\Modules\TrainingNutrition\Data\PlanEvidenceKind;
use App\Modules\TrainingNutrition\Data\PlanGet;
use App\Modules\TrainingNutrition\Data\PlanProgress;
use App\Modules\TrainingNutrition\Data\PlanSave;
use App\Modules\TrainingNutrition\Data\PlanSlot;
use App\Modules\TrainingNutrition\Data\ProgressionRule;
use App\Modules\TrainingNutrition\Services\TrainingPlanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Store and inspect Hermes-authored plan proposals and revisions. save never activates. activate requires explicit user acceptance or an ID of a rule in the active accepted plan; rule progression may change exercise prescriptions but not goals, schedule, or rule definitions. get without plan_id reads the active plan.')]
class TrainingPlanTool extends TrainingNutritionTool
{
    protected string $requestClass = TrainingPlanRequest::class;

    protected string $name = 'training_plan';

    public function __construct(private TrainingPlanService $plans) {}

    protected function run(array $input, User $owner): array
    {
        $data = match ($input['action']) {
            'save' => new PlanSave(
                $input['prior_plan_id'] ?? null,
                $input['title'],
                new PlanDirection($input['direction']['focus'], $input['direction']['weeks']),
                array_map(PlanSlot::fromValidated(...), $input['slots']),
                array_map(
                    fn (array $rule): ProgressionRule => new ProgressionRule($rule['rule_id'], $rule['text']),
                    $input['progression_rules'] ?? [],
                ),
                $input['rationale'] ?? null,
                array_map(
                    fn (array $item): PlanEvidence => new PlanEvidence(
                        PlanEvidenceKind::from($item['kind']),
                        $item['reference'],
                        $item['date'] ?? null,
                    ),
                    $input['evidence'] ?? [],
                ),
            ),
            'get' => new PlanGet($input['plan_id'] ?? null),
            'history' => new PageQuery($input['cursor'] ?? null, $input['limit'] ?? 50),
            'activate' => $input['authorization_basis'] === 'accepted_rule' ? new PlanProgress(
                $input['plan_id'],
                $input['expected_active_plan_id'],
                $input['authorization_reference'],
                $input['rule_id'],
            ) : new PlanAccept(
                $input['plan_id'],
                $input['expected_active_plan_id'],
                $input['authorization_reference'],
            ),
        };

        return $this->plans->run($data, $input, $owner->id);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['save', 'get', 'history', 'activate'])->required(),
            'request_id' => $schema->string(),
            'plan_id' => $schema->integer(),
            'prior_plan_id' => $schema->integer(),
            'expected_active_plan_id' => $schema->integer()->nullable(),
            'authorization_basis' => $schema->string()->enum(['user_acceptance', 'accepted_rule']),
            'authorization_reference' => $schema->string(),
            'rule_id' => $schema->string(),
            'title' => $schema->string(),
            'direction' => $schema->object([
                'focus' => $schema->string(),
                'weeks' => $schema->integer(),
            ])->withoutAdditionalProperties(),
            'slots' => $schema->array()->items($schema->object([
                'slot_id' => $schema->string(),
                'date' => $schema->string(),
                'activity_type' => $schema->string()->enum(['strength', 'run', 'mixed', 'other']),
                'intensity' => $schema->string()->enum(['light', 'medium', 'high']),
                'optional' => $schema->boolean(),
                'exercises' => $schema->array()->items($schema->object([
                    'exercise_id' => $schema->integer(),
                    'sets' => $schema->integer(),
                    'reps' => $schema->integer(),
                    'load_kg' => $schema->number(),
                    'duration_minutes' => $schema->number(),
                ])->withoutAdditionalProperties()),
            ])->withoutAdditionalProperties()),
            'progression_rules' => $schema->array()->items($schema->object([
                'rule_id' => $schema->string(),
                'text' => $schema->string(),
            ])->withoutAdditionalProperties()),
            'rationale' => $schema->string(),
            'evidence' => $schema->array()->items($schema->object([
                'kind' => $schema->string()->enum(['oura', 'session', 'nutrition', 'owner_report']),
                'reference' => $schema->string(),
                'date' => $schema->string(),
            ])->withoutAdditionalProperties()),
            'cursor' => $schema->string(),
            'limit' => $schema->integer(),
        ];
    }
}
