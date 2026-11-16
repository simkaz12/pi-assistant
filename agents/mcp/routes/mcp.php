<?php

use App\Mcp\Servers\OuraServer;
use App\Mcp\Servers\SystemServer;
use App\Mcp\Servers\TrainingNutritionServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/oura', OuraServer::class)->middleware(['vpn', 'oura-tools']);
Mcp::web('/mcp/training-nutrition', TrainingNutritionServer::class)
    ->middleware(['vpn', 'training-nutrition-tools']);
Mcp::web('/mcp/system', SystemServer::class)->middleware(['vpn', 'system-tools']);
