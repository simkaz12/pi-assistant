<?php

namespace App\Modules\Oura\Services;

use App\Modules\Oura\Models\OuraConnection;
use App\Modules\Oura\Models\OuraDocument;
use App\Modules\Oura\Models\OuraSyncState;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class OuraContextService
{
    public const METRICS = [
        'sleep_score' => ['dataset' => 'daily_sleep', 'path' => 'score', 'unit' => 'score', 'aggregation' => 'one daily score'],
        'readiness_score' => ['dataset' => 'daily_readiness', 'path' => 'score', 'unit' => 'score', 'aggregation' => 'one daily score'],
        'activity_score' => ['dataset' => 'daily_activity', 'path' => 'score', 'unit' => 'score', 'aggregation' => 'one daily score'],
        'resting_heart_rate' => ['dataset' => 'sleep', 'path' => 'lowest_heart_rate', 'unit' => 'bpm', 'aggregation' => 'mean of sleep episode minima per day'],
        'hrv' => ['dataset' => 'sleep', 'path' => 'average_hrv', 'unit' => 'ms', 'aggregation' => 'mean of sleep episode averages per day'],
        'sleep_duration' => ['dataset' => 'sleep', 'path' => 'total_sleep_duration', 'unit' => 'seconds', 'aggregation' => 'sum of sleep episodes per day'],
        'temperature_deviation' => ['dataset' => 'daily_readiness', 'path' => 'temperature_deviation', 'unit' => '°C deviation', 'aggregation' => 'one daily deviation'],
        'daytime_stress' => ['dataset' => 'daily_stress', 'path' => 'stress_high', 'unit' => 'seconds', 'aggregation' => 'one daily duration'],
        'steps' => ['dataset' => 'daily_activity', 'path' => 'steps', 'unit' => 'steps', 'aggregation' => 'one daily count'],
        'spo2' => ['dataset' => 'daily_spo2', 'path' => 'spo2_percentage.average', 'unit' => '%', 'aggregation' => 'one daily average'],
        'cardiovascular_age' => ['dataset' => 'daily_cardiovascular_age', 'path' => 'vascular_age', 'unit' => 'years', 'aggregation' => 'one daily estimate'],
        'vo2_max' => ['dataset' => 'vO2_max', 'path' => 'vo2_max', 'unit' => 'mL/kg/min', 'aggregation' => 'one daily estimate'],
        'heart_rate' => ['dataset' => 'heartrate', 'path' => 'bpm', 'unit' => 'bpm', 'aggregation' => 'mean of samples per day'],
    ];

    public function __construct(private OuraConnectionService $connections, private OuraDatasetRegistry $registry) {}

    public function query(array $input): array
    {
        $action = $input['action'] ?? null;
        if (! in_array($action, ['today', 'sleep', 'recovery', 'stress', 'activity', 'trends', 'correlations', 'raw'], true)) {
            throw new InvalidArgumentException('Unsupported wellbeing_context action.');
        }

        $connection = $this->connections->owner();
        $today = CarbonImmutable::now(config('services.oura.timezone'))->toDateString();

        if ($action === 'raw') {
            return $this->raw($connection, $input);
        }

        if ($action === 'trends') {
            return $this->trends($connection, $input, $today);
        }

        if ($action === 'correlations') {
            return $this->correlations($connection, $input, $today);
        }

        [$from, $through] = $this->range($input, $today, 90);
        $datasets = match ($action) {
            'today' => ['daily_sleep', 'sleep', 'daily_readiness', 'daily_stress', 'daily_activity'],
            'sleep' => ['daily_sleep', 'sleep', 'sleep_time'],
            'recovery' => ['daily_readiness', 'daily_resilience', 'sleep', 'rest_mode_period', 'vO2_max', 'daily_cardiovascular_age'],
            'stress' => ['daily_stress', 'daily_resilience'],
            'activity' => ['daily_activity', 'workout', 'session', 'ring_battery_level'],
        };

        $documents = OuraDocument::query()
            ->where('oura_connection_id', $connection->id)
            ->whereIn('dataset', $datasets)
            ->whereBetween('source_day', [$from, $through])
            ->whereNull('source_deleted_at')
            ->orderBy('source_day')->orderBy('id')
            ->limit(501)->get();

        if ($documents->count() > 500) {
            throw new InvalidArgumentException('Range has more than 500 records; narrow dates or use raw pagination.');
        }

        return [
            'action' => $action,
            'from' => $from,
            'through' => $through,
            'observations' => $documents->map(fn (OuraDocument $document) => $this->record($document, false))->all(),
            'coverage' => $this->coverage($connection, $datasets, $from, $through),
            'freshness' => $this->freshness($connection, $datasets),
        ];
    }

    private function raw(OuraConnection $connection, array $input): array
    {
        $dataset = $input['dataset'] ?? null;
        if (! is_string($dataset)) {
            throw new InvalidArgumentException('raw requires a dataset.');
        }
        $this->registry->get($dataset);
        $today = CarbonImmutable::now(config('services.oura.timezone'))->toDateString();
        [$from, $through] = $this->range($input, $today, 366);
        $limit = $this->limit($input, 50);
        $cursor = $this->cursor($input);

        $documents = OuraDocument::query()
            ->where('oura_connection_id', $connection->id)
            ->where('dataset', $dataset)
            ->whereBetween('source_day', [$from, $through])
            ->whereNull('source_deleted_at')
            ->where('id', '>', $cursor)
            ->orderBy('id')->limit($limit + 1)->get();
        $page = $documents->take($limit);

        return [
            'action' => 'raw',
            'dataset' => $dataset,
            'from' => $from,
            'through' => $through,
            'records' => $page->map(fn (OuraDocument $document) => $this->record($document, true))->all(),
            'next_cursor' => $documents->count() > $limit ? (string) $page->last()->id : null,
            'coverage' => $this->coverage($connection, [$dataset], $from, $through),
            'freshness' => $this->freshness($connection, [$dataset]),
        ];
    }

    private function trends(OuraConnection $connection, array $input, string $today): array
    {
        $metric = $input['metric'] ?? null;
        if (! is_string($metric) || ! isset(self::METRICS[$metric])) {
            throw new InvalidArgumentException('trends requires a supported metric.');
        }

        $days = $this->dailyValues($connection, $metric, CarbonImmutable::parse($today)->subDays(89)->toDateString(), $today);
        $windows = [];

        foreach ([7 => 4, 28 => 14, 90 => 45] as $size => $minimum) {
            $from = CarbonImmutable::parse($today)->subDays($size - 1)->toDateString();
            $valid = array_filter($days, fn ($day) => $day['day'] >= $from && $day['day'] <= $today);
            $count = count($valid);
            $windows[(string) $size] = [
                'from' => $from,
                'through' => $today,
                'valid_days' => $count,
                'expected_days' => $size,
                'minimum_valid_days' => $minimum,
                'threshold_met' => $count >= $minimum,
                'mean' => $count >= $minimum ? array_sum(array_column($valid, 'value')) / $count : null,
            ];
        }

        return [
            'action' => 'trends',
            'metric' => $metric,
            'unit' => self::METRICS[$metric]['unit'],
            'aggregation' => self::METRICS[$metric]['aggregation'],
            'daily_values' => array_values($days),
            'windows' => $windows,
            'coverage' => $this->coverage(
                $connection,
                [self::METRICS[$metric]['dataset']],
                CarbonImmutable::parse($today)->subDays(89)->toDateString(),
                $today,
            ),
            'freshness' => $this->freshness($connection, [self::METRICS[$metric]['dataset']]),
        ];
    }

    private function correlations(OuraConnection $connection, array $input, string $today): array
    {
        $metric = $input['metric'] ?? null;
        if (! is_string($metric) || ! isset(self::METRICS[$metric])) {
            throw new InvalidArgumentException('correlations requires a supported metric.');
        }

        [$from, $through] = $this->range($input, $today, 90);
        $tags = OuraDocument::query()
            ->where('oura_connection_id', $connection->id)
            ->whereIn('dataset', ['tag', 'enhanced_tag'])
            ->whereBetween('source_day', [$from, $through])
            ->whereNull('source_deleted_at')
            ->orderBy('source_day')->limit(21)->get();

        if ($tags->count() > 20) {
            throw new InvalidArgumentException('Range has more than 20 tags; narrow dates.');
        }

        $before = CarbonImmutable::parse($from)->subDays(7)->toDateString();
        $after = CarbonImmutable::parse($through)->addDays(7)->toDateString();
        $values = $this->dailyValues($connection, $metric, $before, $after);
        $evidence = [];

        foreach ($tags as $tag) {
            $day = $tag->source_day?->toDateString();
            if (! $day) {
                continue;
            }

            $items = array_values(array_filter($values, fn ($value) => abs(
                CarbonImmutable::parse($value['day'])->diffInDays(CarbonImmutable::parse($day), false),
            ) <= 7));
            $beforeValues = array_values(array_filter($items, fn ($value) => $value['day'] < $day));
            $duringValues = array_values(array_filter($items, fn ($value) => $value['day'] === $day));
            $afterValues = array_values(array_filter($items, fn ($value) => $value['day'] > $day));
            $beforeCoverage = $this->windowCoverage($beforeValues, CarbonImmutable::parse($day)->subDays(7), 7);
            $duringCoverage = $this->windowCoverage($duringValues, CarbonImmutable::parse($day), 1);
            $afterCoverage = $this->windowCoverage($afterValues, CarbonImmutable::parse($day)->addDay(), 7);
            $evidence[] = [
                'tag' => $this->record($tag, false),
                'before' => $beforeValues,
                'during' => $duringValues,
                'after' => $afterValues,
                'windows' => [
                    'before' => $beforeCoverage,
                    'during' => $duringCoverage,
                    'after' => $afterCoverage,
                ],
                'evidence_status' => $beforeCoverage['observed_valid_days'] >= 4
                    && $afterCoverage['observed_valid_days'] >= 4
                    ? 'descriptive_only'
                    : 'insufficient',
            ];
        }

        return [
            'action' => 'correlations',
            'metric' => $metric,
            'unit' => self::METRICS[$metric]['unit'],
            'tag_count' => count($evidence),
            'pattern_supported' => false,
            'evidence' => $evidence,
            'coverage' => $this->coverage($connection, ['tag', 'enhanced_tag', self::METRICS[$metric]['dataset']], $from, $through),
            'freshness' => $this->freshness($connection, ['tag', 'enhanced_tag', self::METRICS[$metric]['dataset']]),
        ];
    }

    private function dailyValues(OuraConnection $connection, string $metric, string $from, string $through): array
    {
        $definition = self::METRICS[$metric];
        $documents = OuraDocument::query()
            ->where('oura_connection_id', $connection->id)
            ->where('dataset', $definition['dataset'])
            ->whereBetween('source_day', [$from, $through])
            ->whereNull('source_deleted_at')
            ->lazyById(500);

        $grouped = [];
        foreach ($documents as $document) {
            $value = data_get($document->payload, $definition['path']);
            if (! is_numeric($value)) {
                continue;
            }

            $day = $document->source_day->toDateString();
            if (! isset($grouped[$day])) {
                $grouped[$day] = ['sum' => 0.0, 'count' => 0, 'source_ids' => []];
            }

            $grouped[$day]['sum'] += (float) $value;
            $grouped[$day]['count']++;
            if (count($grouped[$day]['source_ids']) < 5) {
                $grouped[$day]['source_ids'][] = $document->source_id;
            }
        }

        ksort($grouped);
        $result = [];
        foreach ($grouped as $day => $entries) {
            $result[] = [
                'day' => $day,
                'value' => $metric === 'sleep_duration'
                    ? $entries['sum']
                    : $entries['sum'] / $entries['count'],
                'unit' => $definition['unit'],
                'sample_count' => $entries['count'],
                'source_ids' => $entries['source_ids'],
            ];
        }

        return $result;
    }

    private function windowCoverage(array $values, CarbonImmutable $start, int $days): array
    {
        $observed = array_column($values, 'day');
        $expected = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $expected[] = $start->addDays($offset)->toDateString();
        }

        return [
            'from' => $expected[0],
            'through' => $expected[$days - 1],
            'expected_days' => $days,
            'observed_valid_days' => count($observed),
            'missing_days' => array_values(array_diff($expected, $observed)),
        ];
    }

    private function record(OuraDocument $document, bool $raw): array
    {
        $record = [
            'dataset' => $document->dataset,
            'source_id' => $document->source_id,
            'day' => $document->source_day?->toDateString(),
            'timestamp' => $document->payload['timestamp'] ?? null,
            'provenance' => $document->provenance,
            'fetched_at' => $document->fetched_at?->toIso8601String(),
        ];

        $record['data'] = $raw ? $document->payload : $this->summary($document);
        $record['units'] = $raw ? null : array_intersect_key([
            'score' => 'score',
            'total_sleep_duration' => 'seconds',
            'average_hrv' => 'ms',
            'lowest_heart_rate' => 'bpm',
            'temperature_deviation' => '°C deviation',
            'stress_high' => 'seconds',
            'recovery_high' => 'seconds',
            'steps' => 'steps',
            'active_calories' => 'kcal',
            'vascular_age' => 'years',
            'vo2_max' => 'mL/kg/min',
        ], $record['data']);

        return $record;
    }

    private function summary(OuraDocument $document): array
    {
        $payload = $document->payload ?? [];
        $fields = [
            'score', 'total_sleep_duration', 'average_hrv', 'lowest_heart_rate',
            'temperature_deviation', 'stress_high', 'recovery_high',
            'steps', 'active_calories', 'spo2_percentage', 'vascular_age',
            'vo2_max', 'activity', 'tag_type_code', 'tag_type', 'comment',
            'start_datetime', 'end_datetime', 'battery_level',
        ];

        return array_intersect_key($payload, array_flip($fields));
    }

    private function coverage(OuraConnection $connection, array $datasets, string $from, string $through): array
    {
        $result = [];
        foreach (array_unique($datasets) as $dataset) {
            $state = OuraSyncState::query()->where('oura_connection_id', $connection->id)
                ->where('dataset', $dataset)->first();

            $result[$dataset] = [
                'status' => $state?->status ?? 'not_started',
                'history_floor' => $state?->history_floor?->toDateString(),
                'import_through' => $state?->window_end?->toDateString(),
                'requested_from' => $from,
                'requested_through' => $through,
                'complete' => $state?->status === 'complete',
                'access_denied' => $state?->status === 'access_denied',
            ];
        }

        return $result;
    }

    private function freshness(OuraConnection $connection, array $datasets): array
    {
        return [
            'connection_status' => $connection->status,
            'last_successful_sync_at' => $connection->last_successful_sync_at?->toIso8601String(),
            'dataset_last_sync' => OuraSyncState::query()
                ->where('oura_connection_id', $connection->id)
                ->whereIn('dataset', $datasets)->pluck('last_successful_sync_at', 'dataset')->all(),
        ];
    }

    private function range(array $input, string $today, int $maxDays): array
    {
        $from = $input['from'] ?? ($input['date'] ?? $today);
        $through = $input['through'] ?? ($input['date'] ?? $today);
        if (! is_string($from) || ! is_string($through)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $through)) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }

        try {
            $first = CarbonImmutable::createFromFormat('!Y-m-d', $from);
            $last = CarbonImmutable::createFromFormat('!Y-m-d', $through);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }

        if ($first->toDateString() !== $from || $last->toDateString() !== $through
            || $first->isAfter($last) || $first->diffInDays($last) >= $maxDays) {
            throw new InvalidArgumentException('Date range is invalid or too large.');
        }

        return [$from, $through];
    }

    private function limit(array $input, int $default): int
    {
        $value = $input['limit'] ?? $default;
        if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < 1 || $value > 100) {
            throw new InvalidArgumentException('limit must be 1..100.');
        }

        return (int) $value;
    }

    private function cursor(array $input): int
    {
        $cursor = $input['cursor'] ?? 0;
        if (filter_var($cursor, FILTER_VALIDATE_INT) === false || $cursor < 0) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        return (int) $cursor;
    }
}
