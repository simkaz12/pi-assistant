<?php

return [
    'user_id' => env('TRAINING_NUTRITION_USER_ID'),
    'mcp_token' => env('TRAINING_NUTRITION_MCP_TOKEN'),
    'timezone' => env('TRAINING_NUTRITION_TIMEZONE', 'UTC'),
    'match_overlap' => 0.8,
    'reconcile_batch' => 50,
];
