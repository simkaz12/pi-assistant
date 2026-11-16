<?php

namespace App\Mcp\Tools;

use App\Modules\Oura\Services\OuraDataService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Export stored Oura records in pages or explicitly delete them. export accepts from/through or all=true, dataset, cursor, limit. delete_preview returns a short-lived token for the exact requested range; delete_confirm consumes that token. Whole-history deletion disconnects Oura.')]
class OuraDataTool extends Tool
{
    protected string $name = 'oura_data';

    public function __construct(private OuraDataService $data) {}

    public function handle(Request $request): Response
    {
        $input = [];
        foreach (['action', 'from', 'through', 'all', 'dataset', 'cursor', 'limit', 'confirmation_token'] as $key) {
            $input[$key] = $request->get($key);
        }

        try {
            return Response::text(json_encode($this->data->run($input), JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            return Response::error($e instanceof InvalidArgumentException ? $e->getMessage() : 'Oura data action failed.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('export, delete_preview, or delete_confirm'),
            'from' => $schema->string()->description('Inclusive YYYY-MM-DD start'),
            'through' => $schema->string()->description('Inclusive YYYY-MM-DD end'),
            'all' => $schema->boolean()->description('Select whole history'),
            'dataset' => $schema->string()->description('Optional export dataset'),
            'cursor' => $schema->string()->description('Export page cursor'),
            'limit' => $schema->integer()->description('Export page size 1..100'),
            'confirmation_token' => $schema->string()->description('Token returned by delete_preview'),
        ];
    }
}
