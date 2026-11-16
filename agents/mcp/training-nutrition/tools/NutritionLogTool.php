<?php

namespace App\Mcp\Tools;

use App\Http\Requests\TrainingNutrition\NutritionLogRequest;
use App\Models\User;
use App\Modules\TrainingNutrition\Data\NutritionAmend;
use App\Modules\TrainingNutrition\Data\NutritionCategory;
use App\Modules\TrainingNutrition\Data\NutritionEvidenceKind;
use App\Modules\TrainingNutrition\Data\NutritionGet;
use App\Modules\TrainingNutrition\Data\NutritionHistory;
use App\Modules\TrainingNutrition\Data\NutritionItem;
use App\Modules\TrainingNutrition\Data\NutritionRecord;
use App\Modules\TrainingNutrition\Data\NutritionSummary;
use App\Modules\TrainingNutrition\Data\NutritionTimePrecision;
use App\Modules\TrainingNutrition\Services\NutritionLogService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Record owner-reported food, drink, caffeine, alcohol and context without meal advice. Hermes interprets Telegram photos first; send only structured observations, never image bytes or URLs. Homemade meals can omit amounts. Label values keep their serving basis and do not prove consumed quantity. Actions: record, amend, get, history, summary.')]
class NutritionLogTool extends TrainingNutritionTool
{
    protected string $requestClass = NutritionLogRequest::class;

    protected string $name = 'nutrition_log';

    public function __construct(private NutritionLogService $nutrition) {}

    protected function run(array $input, User $owner): array
    {
        $items = isset($input['items'])
            ? array_map(NutritionItem::fromValidated(...), $input['items'])
            : null;

        $data = match ($input['action']) {
            'record' => new NutritionRecord(
                NutritionTimePrecision::from($input['time_precision']),
                $input['observed_at'] ?? null,
                $input['observed_date'] ?? null,
                $input['meal_period'] ?? null,
                NutritionCategory::from($input['category']),
                $input['description'] ?? null,
                $items ?? [],
                $input['context'] ?? [],
                NutritionEvidenceKind::from($input['evidence_kind']),
            ),
            'amend' => new NutritionAmend(
                $input['entry_id'],
                $input['expected_version'],
                isset($input['time_precision']) ? NutritionTimePrecision::from($input['time_precision']) : null,
                $input['observed_at'] ?? null,
                $input['observed_date'] ?? null,
                $input['meal_period'] ?? null,
                isset($input['category']) ? NutritionCategory::from($input['category']) : null,
                $input['description'] ?? null,
                $items,
                $input['context'] ?? null,
                isset($input['evidence_kind']) ? NutritionEvidenceKind::from($input['evidence_kind']) : null,
            ),
            'get' => new NutritionGet($input['entry_id']),
            'history' => new NutritionHistory(
                $input['from'] ?? null,
                $input['through'] ?? null,
                $input['cursor'] ?? null,
                $input['limit'] ?? 50,
            ),
            'summary' => new NutritionSummary($input['from'], $input['through']),
        };

        return $this->nutrition->run($data, $input, $owner->id);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['record', 'amend', 'get', 'history', 'summary'])->required(),
            'request_id' => $schema->string(),
            'entry_id' => $schema->integer(),
            'expected_version' => $schema->integer(),
            'observed_at' => $schema->string()->nullable(),
            'observed_date' => $schema->string(),
            'time_precision' => $schema->string()->enum(['exact', 'approximate', 'date', 'meal_period']),
            'meal_period' => $schema->string()->enum(['breakfast', 'lunch', 'dinner', 'snack', 'other'])->nullable(),
            'category' => $schema->string()->enum(['meal', 'drink', 'caffeine', 'alcohol', 'other']),
            'description' => $schema->string()->nullable(),
            'items' => $schema->array()->items($schema->object([
                'name' => $schema->string(),
                'kind' => $schema->string()->enum(['food', 'drink']),
                'amount' => $schema->object([
                    'value' => $schema->number(),
                    'unit' => $schema->string()->enum(['g', 'ml', 'portion', 'unit']),
                    'basis' => $schema->string()->enum(['consumed', 'prepared']),
                    'source' => $schema->string()->enum(['owner_reported', 'label_transcribed']),
                ])->withoutAdditionalProperties(),
                'label' => $schema->object([
                    'value' => $schema->number(),
                    'unit' => $schema->string(),
                    'basis' => $schema->string()->enum(['per_serving', 'per_100g', 'per_100ml']),
                    'serving_size' => $schema->string(),
                ])->withoutAdditionalProperties(),
            ])->withoutAdditionalProperties()),
            'context' => $schema->object([
                'hunger' => $schema->string(),
                'digestion' => $schema->string(),
                'energy' => $schema->string(),
            ])->withoutAdditionalProperties(),
            'evidence_kind' => $schema->string()->enum(['owner_reported', 'label_transcribed', 'image_inferred']),
            'from' => $schema->string(),
            'through' => $schema->string(),
            'cursor' => $schema->string(),
            'limit' => $schema->integer(),
        ];
    }
}
