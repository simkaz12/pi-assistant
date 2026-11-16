<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\SystemEventsTool;
use App\Mcp\Tools\SystemProblemsTool;
use App\Mcp\Tools\SystemStatusTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('pi-system')]
#[Version('1.0.0')]
#[Instructions('Read-only health of the Raspberry Pi. system_problems lists open problems with severity, since and alertable. system_status returns the latest collected snapshot. system_events pages the problem and notice log by id.')]
class SystemServer extends Server
{
    protected array $tools = [
        SystemStatusTool::class,
        SystemProblemsTool::class,
        SystemEventsTool::class,
    ];
}
