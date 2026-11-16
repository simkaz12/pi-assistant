<?php

namespace App\Modules\Oura\Services;

use InvalidArgumentException;

class OuraDatasetRegistry
{
    private const DATASETS = [
        'daily_activity' => ['scope' => 'daily', 'time' => 'date'],
        'daily_cardiovascular_age' => ['scope' => 'heart_health', 'time' => 'date'],
        'daily_readiness' => ['scope' => 'daily', 'time' => 'date'],
        'daily_resilience' => ['scope' => 'heart_health', 'time' => 'date'],
        'daily_sleep' => ['scope' => 'daily', 'time' => 'date'],
        'daily_spo2' => ['scope' => 'spo2', 'time' => 'date'],
        'daily_stress' => ['scope' => 'stress', 'time' => 'date'],
        'enhanced_tag' => ['scope' => 'tag', 'time' => 'date'],
        'heartrate' => ['scope' => 'heartrate', 'time' => 'datetime'],
        'rest_mode_period' => ['scope' => 'daily', 'time' => 'date'],
        'ring_battery_level' => ['scope' => 'daily', 'time' => 'datetime'],
        'ring_configuration' => ['scope' => 'ring_configuration', 'time' => 'date'],
        'session' => ['scope' => 'session', 'time' => 'date'],
        'sleep' => ['scope' => 'daily', 'time' => 'date'],
        'sleep_time' => ['scope' => 'daily', 'time' => 'date'],
        'tag' => ['scope' => 'tag', 'time' => 'date'],
        'vO2_max' => ['scope' => 'heart_health', 'time' => 'date'],
        'workout' => ['scope' => 'workout', 'time' => 'date'],
    ];

    public function names(): array
    {
        return array_keys(self::DATASETS);
    }

    public function get(string $name): array
    {
        return self::DATASETS[$name] ?? throw new InvalidArgumentException('Unsupported Oura dataset.');
    }

    public function collectionPath(string $name): string
    {
        $this->get($name);

        return '/v2/usercollection/'.$name;
    }
}
