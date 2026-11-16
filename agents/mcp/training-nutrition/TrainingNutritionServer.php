<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\NutritionLogTool;
use App\Mcp\Tools\TrainingNutritionDataTool;
use App\Mcp\Tools\TrainingPlanTool;
use App\Mcp\Tools\TrainingProfileTool;
use App\Mcp\Tools\TrainingSessionTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('pi-training-nutrition')]
#[Version('1.0.0')]
#[Instructions('Private training and observational nutrition records for Hermes. Read the profile, active plan, sessions, nutrition history, and separate Oura MCP before making suggestions. Hermes writes proposals; only explicit acceptance activates schedule, goals, or rule changes. Existing accepted progression rules may be applied with their rule ID. Owner strength reports need actual sets/reps or explicit as-prescribed confirmation; a bare done leaves numbers unknown. Oura supplies running distance when available. Hermes interprets Telegram meal photos and sends structured observations; this MCP never receives images or invents portions. Missing records are unknown, not zero. Clarify ambiguous deletion scope before calling delete_preview.')]
class TrainingNutritionServer extends Server
{
    protected array $tools = [
        TrainingProfileTool::class,
        TrainingPlanTool::class,
        TrainingSessionTool::class,
        NutritionLogTool::class,
        TrainingNutritionDataTool::class,
    ];
}
