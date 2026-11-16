<?php

namespace App\Modules\TrainingNutrition\Services;

use App\Modules\TrainingNutrition\Data\NutritionAmend;
use App\Modules\TrainingNutrition\Data\NutritionEvidenceKind;
use App\Modules\TrainingNutrition\Data\NutritionGet;
use App\Modules\TrainingNutrition\Data\NutritionHistory;
use App\Modules\TrainingNutrition\Data\NutritionRecord;
use App\Modules\TrainingNutrition\Data\NutritionSummary;
use App\Modules\TrainingNutrition\Data\NutritionTimePrecision;
use App\Modules\TrainingNutrition\Models\NutritionEntry;
use App\Modules\TrainingNutrition\Models\TrainingProfile;
use App\Modules\TrainingNutrition\Resources\NutritionEntryResource;
use Carbon\CarbonImmutable;

class NutritionLogService
{
    public function __construct(private TrainingMutationService $mutations) {}

    public function run(
        NutritionRecord|NutritionAmend|NutritionGet|NutritionHistory|NutritionSummary $input,
        array $payload,
        int $ownerId,
    ): array {
        return match ($input::class) {
            NutritionRecord::class => $this->mutations->mutate(
                $ownerId,
                'nutrition_log.record',
                $payload,
                fn (): array => $this->recordEntry($input, $ownerId),
            ),
            NutritionAmend::class => $this->mutations->mutate(
                $ownerId,
                'nutrition_log.amend',
                $payload,
                fn (): array => $this->amendEntry($input, $ownerId),
            ),
            NutritionGet::class => $this->get($input, $ownerId),
            NutritionHistory::class => $this->history($input, $ownerId),
            NutritionSummary::class => $this->summary($input, $ownerId),
        };
    }

    private function timezone(int $ownerId): string
    {
        return TrainingProfile::where('user_id', $ownerId)
            ->value('timezone') ?? 'UTC';
    }

    private function get(NutritionGet $input, int $ownerId): array
    {
        return ['entry' => NutritionEntryResource::make(NutritionEntry::forOwner($ownerId)->findOrFail($input->entryId))->resolve()];
    }

    private function history(NutritionHistory $input, int $ownerId): array
    {
        $limit = $input->limit;
        $filter = ['action' => 'history', 'kind' => 'nutrition',
            'from' => $input->from, 'through' => $input->through];
        $afterId = $this->mutations->readPageCursor($input->cursor, $ownerId, $filter);
        $query = NutritionEntry::where('user_id', $ownerId)->where('id', '>', $afterId);
        if ($input->from !== null) {
            $query->whereDate('observed_date', '>=', $input->from);
        }
        if ($input->through !== null) {
            $query->whereDate('observed_date', '<=', $input->through);
        }
        $records = $query->orderBy('id')->limit($limit + 1)->get();

        return [
            'entries' => NutritionEntryResource::collection($records->take($limit))->resolve(),
            'next_cursor' => $records->count() > $limit
                ? $this->mutations->pageCursor($ownerId, $filter, $records[$limit - 1]->id)
                : null,
        ];
    }

    private function summary(NutritionSummary $input, int $ownerId): array
    {
        $entries = NutritionEntry::where('user_id', $ownerId)
            ->whereBetween('observed_date', [$input->from, $input->through])->get();
        $known = [];
        $unknown = 0;
        foreach ($entries as $entry) {
            foreach ($entry->items as $item) {
                if (isset($item['amount']['value'])) {
                    $unit = $item['amount']['unit'];
                    $known[$unit] = ($known[$unit] ?? 0) + $item['amount']['value'];
                } else {
                    $unknown++;
                }
            }
        }

        return [
            'from' => $input->from, 'through' => $input->through,
            'entries_count' => $entries->count(),
            'days_with_logs' => $entries->pluck('observed_date')->unique()->count(),
            'categories' => $entries->countBy('category')->all(),
            'known_item_amounts_by_unit' => $known,
            'items_with_unknown_amount' => $unknown,
            'whole_day_intake_known' => false,
        ];
    }

    private function recordEntry(NutritionRecord $data, int $ownerId): array
    {
        $entry = NutritionEntry::create([
            'user_id' => $ownerId,
        ] + $this->entryValues($data, null, $ownerId));

        return ['entry' => NutritionEntryResource::make($entry)->resolve()];
    }

    private function amendEntry(NutritionAmend $data, int $ownerId): array
    {
        $entry = NutritionEntry::forOwner($ownerId)->findOrFail($data->entryId);
        if ($entry->version !== $data->expectedVersion) {
            throw new TrainingNutritionException('version_conflict', 'Record version changed.');
        }

        $entry->fill($this->entryValues($data, $entry, $ownerId));
        $entry->version++;
        $entry->save();

        return ['entry' => NutritionEntryResource::make($entry)->resolve()];
    }

    private function entryValues(NutritionRecord|NutritionAmend $data, ?NutritionEntry $entry, int $ownerId): array
    {
        $precision = $data->timePrecision
            ?? ($entry ? NutritionTimePrecision::from($entry->time_precision) : null);
        $time = $data->observedAt ?? $entry?->observed_at?->toIso8601String();
        $date = $data->observedDate ?? $entry?->observed_date?->toDateString();
        $period = $data->mealPeriod ?? $entry?->meal_period;

        if (in_array($precision, [NutritionTimePrecision::Exact, NutritionTimePrecision::Approximate], true)) {
            if (! $time || ! preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/', $time)) {
                throw new TrainingNutritionException('invalid_input', 'Exact and approximate times need an offset.');
            }
            $parsed = CarbonImmutable::parse($time);
            $date = $parsed->setTimezone($this->timezone($ownerId))->toDateString();
            $offset = $parsed->format('P');
            $time = $parsed;
        } else {
            if (! $date || ($precision === NutritionTimePrecision::MealPeriod && ! $period)) {
                throw new TrainingNutritionException('invalid_input', 'Date and meal period are required for imprecise time.');
            }
            $time = null;
            $offset = null;
        }

        $items = $data->items !== null
            ? array_map(fn ($item): array => $item->toArray(), $data->items)
            : ($entry?->items ?? []);
        $hasInferredAmount = collect($items)->contains(
            fn (array $item): bool => isset($item['amount']['value'])
                && ($item['amount']['source'] ?? null) !== 'owner_reported',
        );

        $evidenceKind = $data->evidenceKind
            ?? ($entry ? NutritionEvidenceKind::from($entry->evidence_kind) : null);
        if ($evidenceKind === NutritionEvidenceKind::ImageInferred && $hasInferredAmount) {
            throw new TrainingNutritionException('invalid_input', 'Image inference cannot establish consumed amount.');
        }

        return [
            'observed_at' => $time,
            'observed_date' => $date,
            'time_precision' => $precision?->value,
            'time_offset' => $offset,
            'meal_period' => $period,
            'category' => $data->category?->value ?? $entry?->category,
            'description' => $data->description ?? $entry?->description,
            'items' => $items,
            'context' => $data->context ?? $entry?->context ?? [],
            'evidence_kind' => $evidenceKind?->value,
        ];
    }
}
