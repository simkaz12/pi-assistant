<?php

namespace App\Mcp\Tools;

use App\Modules\System\Data\Problem;
use App\Modules\System\Services\SystemReader;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Open problems, critical first. Each has key, severity, title, detail, since and alertable. alertable is true for a problem that has been critical for five minutes. collector:stale appears when the snapshot is older than three minutes.')]
class SystemProblemsTool extends Tool
{
    protected string $name = 'system_problems';

    public function __construct(private SystemReader $reader) {}

    public function handle(Request $request): Response
    {
        $problems = array_map(fn (Problem $problem) => $problem->toArray(), $this->reader->problems());

        return Response::text(json_encode(['problems' => $problems], JSON_THROW_ON_ERROR));
    }
}
