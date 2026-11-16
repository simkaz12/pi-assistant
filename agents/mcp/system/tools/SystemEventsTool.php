<?php

namespace App\Mcp\Tools;

use App\Modules\System\Enums\SystemEventKind;
use App\Modules\System\Resources\SystemEventResource;
use App\Modules\System\Services\SystemReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Problem and notice log in ascending id order. With after_id, returns the next events after that id; without it, the latest events. limit is clamped to 1..200 (default 50). kind filters by problem_opened, problem_resolved or notice.')]
class SystemEventsTool extends Tool
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    protected string $name = 'system_events';

    public function __construct(private SystemReader $reader) {}

    public function handle(Request $request): Response
    {
        $afterId = $request->get('after_id');
        $limit = max(1, min(self::MAX_LIMIT, (int) ($request->get('limit') ?? self::DEFAULT_LIMIT)));
        $kind = SystemEventKind::tryFrom((string) $request->get('kind'));

        $events = $this->reader->events(
            $afterId === null ? null : (int) $afterId,
            $limit,
            $kind,
        );

        return Response::text(json_encode(
            ['events' => SystemEventResource::collection($events)->resolve()],
            JSON_THROW_ON_ERROR,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'after_id' => $schema->integer()->description('Return events with an id above this'),
            'limit' => $schema->integer()->description('Page size 1..200, default 50'),
            'kind' => $schema->string()->description('problem_opened, problem_resolved or notice'),
        ];
    }
}
