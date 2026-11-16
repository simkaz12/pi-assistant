<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\OuraConnectionTool;
use App\Mcp\Tools\OuraDataTool;
use App\Mcp\Tools\WellbeingContextTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('pi-oura')]
#[Version('1.0.0')]
#[Instructions('Private Oura ring-data surface for Hermes. Use oura_connection to authorize, check status, or disconnect. Use wellbeing_context for stored biometrics and oura_data for export or deliberate deletion. Missing observations are unknown; do not infer medical diagnoses.')]
class OuraServer extends Server
{
    protected array $tools = [
        OuraConnectionTool::class,
        WellbeingContextTool::class,
        OuraDataTool::class,
    ];
}
