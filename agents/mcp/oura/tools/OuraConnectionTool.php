<?php

namespace App\Mcp\Tools;

use App\Modules\Oura\Services\OuraClient;
use App\Modules\Oura\Services\OuraConnectionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Manage the one owner Oura connection. Actions: authorize returns a ten-minute Oura consent URL; status shows scopes and per-dataset import state; disconnect stops collection, removes credentials, and retains history.')]
class OuraConnectionTool extends Tool
{
    protected string $name = 'oura_connection';

    public function __construct(private OuraConnectionService $connections, private OuraClient $client) {}

    public function handle(Request $request): Response
    {
        try {
            $result = match ($request->get('action')) {
                'authorize' => $this->connections->authorize(),
                'status' => $this->connections->status(),
                'disconnect' => $this->connections->disconnect($this->client),
                default => throw new \InvalidArgumentException('action must be authorize, status, or disconnect'),
            };

            return Response::text(json_encode($result, JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            return Response::error($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Oura connection action failed.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('authorize, status, or disconnect'),
        ];
    }
}
