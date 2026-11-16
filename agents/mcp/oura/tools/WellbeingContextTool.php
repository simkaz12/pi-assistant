<?php

namespace App\Mcp\Tools;

use App\Modules\Oura\Services\OuraContextService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Read stored Oura ring observations. Actions: today, sleep, recovery, stress, activity, trends, correlations, raw. Dates are owner-local YYYY-MM-DD. Trends needs metric; raw needs dataset and supports cursor/limit. Responses include coverage and freshness; missing values remain unknown.')]
class WellbeingContextTool extends Tool
{
    protected string $name = 'wellbeing_context';

    public function __construct(private OuraContextService $context) {}

    public function handle(Request $request): Response
    {
        $input = [];
        foreach (['action', 'date', 'from', 'through', 'metric', 'dataset', 'cursor', 'limit'] as $key) {
            $input[$key] = $request->get($key);
        }

        try {
            return Response::text(json_encode($this->context->query($input), JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            return Response::error($e instanceof InvalidArgumentException ? $e->getMessage() : 'Oura context unavailable.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('today, sleep, recovery, stress, activity, trends, correlations, or raw'),
            'date' => $schema->string()->description('Optional owner-local date YYYY-MM-DD'),
            'from' => $schema->string()->description('Optional inclusive start date YYYY-MM-DD'),
            'through' => $schema->string()->description('Optional inclusive end date YYYY-MM-DD'),
            'metric' => $schema->string()->description('Metric for trends or correlations'),
            'dataset' => $schema->string()->description('Dataset for raw'),
            'cursor' => $schema->string()->description('Raw page cursor'),
            'limit' => $schema->integer()->description('Raw page size 1..100'),
        ];
    }
}
