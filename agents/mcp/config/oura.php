<?php

return [
    'client_id' => env('OURA_CLIENT_ID'),
    'client_secret' => env('OURA_CLIENT_SECRET'),
    'redirect_uri' => env('OURA_REDIRECT_URI'),
    'hermes_token' => env('OURA_HERMES_TOKEN'),
    'user_id' => env('OURA_USER_ID', 1),
    'timezone' => env('OURA_TIMEZONE', 'UTC'),
    'history_floor' => env('OURA_HISTORY_FLOOR', '2000-01-01'),
    'window_days' => env('OURA_WINDOW_DAYS', 7),
];
