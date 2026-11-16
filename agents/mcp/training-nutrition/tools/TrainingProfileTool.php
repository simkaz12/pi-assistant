<?php

namespace App\Mcp\Tools;

use App\Http\Requests\TrainingNutrition\TrainingProfileRequest;
use App\Models\User;
use App\Modules\TrainingNutrition\Data\ExerciseSave;
use App\Modules\TrainingNutrition\Data\PageQuery;
use App\Modules\TrainingNutrition\Data\ProfileGet;
use App\Modules\TrainingNutrition\Data\ProfileUpdate;
use App\Modules\TrainingNutrition\Services\TrainingProfileService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Read or edit owner training goals, constraints, schedule preferences, location equipment, and exercise catalogue. Actions: get, update, exercises, save_exercise. update and save_exercise need request_id and expected_version (zero for creation). These are stored facts for Hermes, not generated advice.')]
class TrainingProfileTool extends TrainingNutritionTool
{
    protected string $requestClass = TrainingProfileRequest::class;

    protected string $name = 'training_profile';

    public function __construct(private TrainingProfileService $profiles) {}

    protected function run(array $input, User $owner): array
    {
        $data = match ($input['action']) {
            'get' => new ProfileGet,
            'exercises' => new PageQuery($input['cursor'] ?? null, $input['limit'] ?? 50),
            'update' => new ProfileUpdate(
                $input['expected_version'],
                $input['timezone'] ?? null,
                $input['goals'] ?? null,
                $input['constraints'] ?? null,
                $input['preferences'] ?? null,
                $input['locations'] ?? null,
            ),
            'save_exercise' => new ExerciseSave(
                $input['exercise_id'] ?? null,
                $input['expected_version'],
                $input['name'],
                $input['aliases'] ?? null,
                $input['metadata'] ?? null,
                $input['measures'],
            ),
        };

        return $this->profiles->run($data, $input, $owner->id);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['get', 'update', 'exercises', 'save_exercise'])->required(),
            'request_id' => $schema->string()->description('Unique mutation ID; identical retries return the original result'),
            'expected_version' => $schema->integer()->description('Current version; use zero to create'),
            'timezone' => $schema->string(),
            'goals' => $schema->array()->items($schema->string()),
            'constraints' => $schema->array()->items($schema->string()),
            'preferences' => $schema->object([
                'session_minutes' => $schema->integer(),
                'full_body' => $schema->boolean(),
                'weekly_pattern' => $schema->array()->items($schema->string()),
                'weekend_optional' => $schema->boolean(),
                'running_weekday' => $schema->string(),
                'running_weekend_optional' => $schema->boolean(),
            ])->withoutAdditionalProperties(),
            'locations' => $schema->array()->items($schema->object([
                'name' => $schema->string(),
                'equipment' => $schema->array()->items($schema->string()),
            ])->withoutAdditionalProperties()),
            'exercise_id' => $schema->integer(),
            'name' => $schema->string(),
            'aliases' => $schema->array()->items($schema->string()),
            'metadata' => $schema->object([
                'movement' => $schema->string(),
                'equipment' => $schema->array()->items($schema->string()),
                'body_region' => $schema->string(),
            ])->withoutAdditionalProperties(),
            'measures' => $schema->array()->items($schema->string()->enum([
                'reps', 'load_kg', 'duration_minutes', 'distance_km', 'sets',
            ])),
            'cursor' => $schema->string(),
            'limit' => $schema->integer(),
        ];
    }
}
