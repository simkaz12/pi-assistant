<?php

namespace App\Mcp\Tools;

use App\Http\Requests\TrainingNutrition\TrainingNutritionDataRequest;
use App\Models\User;
use App\Modules\TrainingNutrition\Data\DatedDeletionScope;
use App\Modules\TrainingNutrition\Data\DeleteConfirm;
use App\Modules\TrainingNutrition\Data\DeletePreview;
use App\Modules\TrainingNutrition\Data\ExportQuery;
use App\Modules\TrainingNutrition\Data\RecordKind;
use App\Modules\TrainingNutrition\Data\RecordTarget;
use App\Modules\TrainingNutrition\Data\TimelineQuery;
use App\Modules\TrainingNutrition\Services\TrainingNutritionDataService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Read dated training/nutrition evidence and page through a complete owner export. Deletion needs exact record targets or a bounded dated kind scope, then a single-use ten-minute preview token. Clarify ambiguous owner requests first. This module never deletes underlying Oura, Telegram, Hermes, or backup data.')]
class TrainingNutritionDataTool extends TrainingNutritionTool
{
    protected string $requestClass = TrainingNutritionDataRequest::class;

    protected string $name = 'training_nutrition_data';

    public function __construct(private TrainingNutritionDataService $data) {}

    protected function run(array $input, User $owner): array
    {
        $data = match ($input['action']) {
            'timeline' => new TimelineQuery(
                $input['from'],
                $input['through'],
                $input['cursor'] ?? null,
                $input['limit'] ?? 50,
            ),
            'export' => new ExportQuery(
                array_map(RecordKind::from(...), $input['kinds'] ?? TrainingNutritionDataService::KINDS),
                $input['cursor'] ?? null,
                $input['limit'] ?? 50,
            ),
            'delete_preview' => new DeletePreview(
                array_map(RecordTarget::fromValidated(...), $input['targets'] ?? []),
                isset($input['kinds']) ? new DatedDeletionScope(
                    array_map(RecordKind::from(...), $input['kinds']),
                    $input['from'],
                    $input['through'],
                ) : null,
            ),
            'delete_confirm' => new DeleteConfirm($input['confirmation_token']),
        };

        return $this->data->run($data, $input, $owner->id);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['timeline', 'export', 'delete_preview', 'delete_confirm'])->required(),
            'request_id' => $schema->string(),
            'from' => $schema->string(),
            'through' => $schema->string(),
            'kinds' => $schema->array()->items($schema->string()->enum([
                'profile', 'exercise', 'plan', 'session', 'link', 'nutrition',
            ])),
            'targets' => $schema->array()->items($schema->object([
                'kind' => $schema->string()->enum([
                    'profile', 'exercise', 'plan', 'session', 'link', 'nutrition',
                ]),
                'id' => $schema->integer(),
            ])->withoutAdditionalProperties()),
            'confirmation_token' => $schema->string(),
            'cursor' => $schema->string(),
            'limit' => $schema->integer(),
        ];
    }
}
