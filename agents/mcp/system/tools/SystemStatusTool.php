<?php

namespace App\Mcp\Tools;

use App\Modules\System\Services\SystemReader;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Latest collected Pi snapshot: host, systemd units, Docker containers, scheduled task runs, queue, certificate days, dynamic DNS, WireGuard peers, PC and WoL relay state. snapshot is null before the first collection.')]
class SystemStatusTool extends Tool
{
    protected string $name = 'system_status';

    public function __construct(private SystemReader $reader) {}

    public function handle(Request $request): Response
    {
        $snapshot = $this->reader->snapshot();

        return Response::text(json_encode(['snapshot' => $snapshot?->toArray()], JSON_THROW_ON_ERROR));
    }
}
