<?php

return [
    'trusted_cidrs' => array_filter(array_map('trim', explode(',', env('MCP_TRUSTED_CIDRS', '10.8.0.0/24,127.0.0.0/8')))),

    'token' => env('MCP_TOKEN'),
];
