<?php

return [
    'mcp_token' => env('SYSTEM_MCP_TOKEN'),

    'units' => [
        'nginx',
        'php8.4-fpm',
        'mariadb',
        'redis-server',
        'pi-queue',
        'pi-reverb',
        'pi-now-watch',
        'pi-now-machines',
        'hermes-gateway',
        'tabularium-mcp',
        'cron',
    ],

    'critical_containers' => [
        'docker-infra-pihole-1',
        'docker-infra-jellyfin-1',
        'wg-easy',
        'pi-ollama',
    ],

    'wireguard_container' => 'wg-easy',

    'hermes_bin' => env('HERMES_BIN', '/home/pi/.local/bin/hermes'),

    'hermes_jobs_file' => env('HERMES_JOBS_FILE', '/home/pi/.hermes/cron/jobs.json'),

    'hermes_executions_db' => env('HERMES_EXECUTIONS_DB', '/home/pi/.hermes/cron/executions.db'),

    'switches' => [
        'system-watcher' => ['kind' => 'hermes', 'job' => 'System watcher', 'label' => 'system watcher', 'cadence' => 'every 1m'],
        'oura-sync' => ['kind' => 'schedule', 'tasks' => ['oura:sync recent', 'oura:sync history', 'oura:sync older', 'oura:sync prune'], 'label' => 'oura sync', 'cadence' => 'hourly'],
        'training-reconcile' => ['kind' => 'schedule', 'tasks' => ['training:reconcile-workouts'], 'label' => 'training reconcile', 'cadence' => 'every 5m'],
    ],

    'thresholds' => [
        'disk' => ['warning' => 80, 'critical' => 95],
        'temp' => ['warning' => 75, 'critical' => 85],
        'cert_days' => ['warning' => 21, 'critical' => 7],
        'queue_backlog' => 100,
    ],

    'cert' => [
        'host' => 'home.example.com',
        'port' => 443,
        'connect_address' => '127.0.0.1',
    ],

    'probe_timeout' => 5,

    'slow_probe_ttl_minutes' => 10,

    'stale_after_minutes' => 3,

    'event_retention_days' => 90,
];
