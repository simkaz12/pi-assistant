<?php

namespace App\Modules\TrainingNutrition\Services;

use App\Modules\TrainingNutrition\Data\DeleteConfirm;
use App\Modules\TrainingNutrition\Data\DeletePreview;
use App\Modules\TrainingNutrition\Data\ExportQuery;
use App\Modules\TrainingNutrition\Data\RecordKind;
use App\Modules\TrainingNutrition\Data\RecordVersion;
use App\Modules\TrainingNutrition\Data\TimelineQuery;
use App\Modules\TrainingNutrition\Models\NutritionEntry;
use App\Modules\TrainingNutrition\Models\TrainingExercise;
use App\Modules\TrainingNutrition\Models\TrainingNutritionOperation;
use App\Modules\TrainingNutrition\Models\TrainingPlan;
use App\Modules\TrainingNutrition\Models\TrainingProfile;
use App\Modules\TrainingNutrition\Models\TrainingSession;
use App\Modules\TrainingNutrition\Models\TrainingWorkoutLink;
use App\Modules\TrainingNutrition\Resources\NutritionEntryResource;
use App\Modules\TrainingNutrition\Resources\TrainingExerciseResource;
use App\Modules\TrainingNutrition\Resources\TrainingPlanResource;
use App\Modules\TrainingNutrition\Resources\TrainingProfileResource;
use App\Modules\TrainingNutrition\Resources\TrainingSessionResource;
use App\Modules\TrainingNutrition\Resources\TrainingWorkoutLinkResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class TrainingNutritionDataService
{
    public const KINDS = ['profile', 'exercise', 'plan', 'session', 'link', 'nutrition'];

    public function __construct(
        private TrainingMutationService $mutations,
        private TrainingSessionService $sessions,
    ) {}

    public function run(TimelineQuery|ExportQuery|DeletePreview|DeleteConfirm $input, array $payload, int $ownerId): array
    {
        return match ($input::class) {
            TimelineQuery::class => $this->timeline($input, $ownerId),
            ExportQuery::class => $this->export($input, $ownerId),
            DeletePreview::class => $this->mutations->mutate(
                $ownerId, 'training_nutrition_data.delete_preview', $payload,
                fn (): array => $this->createDeletionPreview($input, $ownerId),
            ),
            DeleteConfirm::class => $this->mutations->mutate(
                $ownerId, 'training_nutrition_data.delete_confirm', $payload,
                fn (): array => $this->confirmDeletion($input, $ownerId),
            ),
        };
    }

    private function timeline(TimelineQuery $input, int $ownerId): array
    {
        $filter = ['action' => 'timeline', 'from' => $input->from, 'through' => $input->through];
        $after = $this->readCursor($input->cursor, $ownerId, $filter);
        $limit = $input->limit;
        $sessions = TrainingSession::forOwner($ownerId)
            ->whereNull('merged_into_id')
            ->where('state', '!=', 'unavailable')
            ->whereBetween('session_day', [$input->from, $input->through]);
        $nutrition = NutritionEntry::forOwner($ownerId)
            ->whereBetween('observed_date', [$input->from, $input->through]);

        $this->afterTimelineCursor($sessions, 'session_day', RecordKind::Session, $after);
        $this->afterTimelineCursor($nutrition, 'observed_date', RecordKind::Nutrition, $after);

        $events = $sessions->orderBy('session_day')->orderBy('id')->limit($limit + 1)->get()
            ->map(fn (TrainingSession $session): array => [
                'kind' => 'session',
                'id' => $session->id,
                'date' => $session->session_day->toDateString(),
                'record' => $this->sessions->detail($session),
            ])
            ->concat($nutrition->orderBy('observed_date')->orderBy('id')->limit($limit + 1)->get()
                ->map(fn (NutritionEntry $entry): array => [
                    'kind' => 'nutrition',
                    'id' => $entry->id,
                    'date' => $entry->observed_date->toDateString(),
                    'record' => NutritionEntryResource::make($entry)->resolve(),
                ]))
            ->sortBy(fn (array $event): string => sprintf('%s:%s:%020d', $event['date'], $event['kind'], $event['id']))
            ->values();
        $page = $events->take($limit);
        $last = $page->last();
        $lastKey = $last ? ['date' => $last['date'], 'kind' => $last['kind'], 'id' => $last['id']] : null;

        return [
            'events' => $page->all(),
            'next_cursor' => $events->count() > $limit ? $this->cursor($ownerId, $filter, $lastKey) : null,
            'coverage' => ['from' => $input->from, 'through' => $input->through,
                'unlogged_intake_unknown' => true],
        ];
    }

    private function export(ExportQuery $input, int $ownerId): array
    {
        $kinds = $input->kinds;
        usort($kinds, fn (RecordKind $left, RecordKind $right): int => strcmp($left->value, $right->value));
        $filter = ['action' => 'export', 'kinds' => array_map(fn (RecordKind $kind): string => $kind->value, $kinds)];
        $position = $this->readCursor($input->cursor, $ownerId, $filter) ?? ['kind' => 0, 'id' => 0];
        $limit = $input->limit;
        $records = [];

        for ($kindIndex = $position['kind']; $kindIndex < count($kinds); $kindIndex++) {
            $kind = $kinds[$kindIndex];
            $afterId = $kindIndex === $position['kind'] ? $position['id'] : 0;
            $remaining = $limit - count($records);
            $batch = $this->ownerRecords($kind, $ownerId)->where('id', '>', $afterId)
                ->orderBy('id')->limit($remaining + 1)->get();

            foreach ($batch->take($remaining) as $record) {
                $records[] = ['kind' => $kind->value, 'record' => $this->recordResource($kind, $record)];
                $afterId = $record->id;
            }

            if ($batch->count() > $remaining) {
                return ['records' => $records,
                    'next_cursor' => $this->cursor($ownerId, $filter, ['kind' => $kindIndex, 'id' => $afterId])];
            }

            if (count($records) === $limit) {
                return ['records' => $records,
                    'next_cursor' => $this->cursor($ownerId, $filter, ['kind' => $kindIndex + 1, 'id' => 0])];
            }
        }

        return ['records' => $records, 'next_cursor' => null];
    }

    private function recordVersion(RecordKind $kind, Model $record): RecordVersion
    {
        return new RecordVersion(
            $kind,
            $record->id,
            $record->version ?? hash('sha256', json_encode($record->toArray(), JSON_THROW_ON_ERROR)),
        );
    }

    private function afterTimelineCursor(Builder $query, string $dateColumn, RecordKind $kind, ?array $after): void
    {
        if (! $after) {
            return;
        }

        $query->where(function (Builder $query) use ($dateColumn, $kind, $after): void {
            $query->where($dateColumn, '>', $after['date']);

            if ($kind->value < $after['kind']) {
                return;
            }

            $query->orWhere(function (Builder $sameDay) use ($dateColumn, $kind, $after): void {
                $sameDay->where($dateColumn, $after['date']);

                if ($kind->value === $after['kind']) {
                    $sameDay->where('id', '>', $after['id']);
                }
            });
        });
    }

    private function ownerRecords(RecordKind $kind, int $ownerId): Builder
    {
        return match ($kind) {
            RecordKind::Profile => TrainingProfile::forOwner($ownerId),
            RecordKind::Exercise => TrainingExercise::forOwner($ownerId),
            RecordKind::Plan => TrainingPlan::forOwner($ownerId),
            RecordKind::Session => TrainingSession::forOwner($ownerId),
            RecordKind::Link => TrainingWorkoutLink::forOwner($ownerId),
            RecordKind::Nutrition => NutritionEntry::forOwner($ownerId),
        };
    }

    private function recordResource(RecordKind $kind, Model $record): array
    {
        return match ($kind) {
            RecordKind::Profile => TrainingProfileResource::make($record)->resolve(),
            RecordKind::Exercise => TrainingExerciseResource::make($record)->resolve(),
            RecordKind::Plan => TrainingPlanResource::make($record)->resolve(),
            RecordKind::Session => TrainingSessionResource::make($record)->resolve(),
            RecordKind::Link => TrainingWorkoutLinkResource::make($record)->resolve(),
            RecordKind::Nutrition => NutritionEntryResource::make($record)->resolve(),
        };
    }

    /** @param array<string, RecordVersion> $selection */
    private function blockingDependencies(int $ownerId, array $selection): array
    {
        $blocking = [];
        foreach ($selection as $item) {
            if ($item->kind !== RecordKind::Plan) {
                continue;
            }
            $planId = $item->id;
            $active = TrainingProfile::where('user_id', $ownerId)->where('active_plan_id', $planId)->first();
            if ($active && ! isset($selection['profile:'.$active->id])) {
                $blocking[] = ['kind' => 'profile', 'id' => $active->id, 'reason' => 'active_plan'];
            }
            foreach (TrainingPlan::where('user_id', $ownerId)->where('prior_plan_id', $planId)->get() as $child) {
                if (! isset($selection['plan:'.$child->id])) {
                    $blocking[] = ['kind' => 'plan', 'id' => $child->id, 'reason' => 'prior_plan'];
                }
            }
            foreach (TrainingSession::where('user_id', $ownerId)->where('plan_id', $planId)->get() as $session) {
                if (! isset($selection['session:'.$session->id])) {
                    $blocking[] = ['kind' => 'session', 'id' => $session->id, 'reason' => 'prescription'];
                }
            }
        }

        return $blocking;
    }

    /**
     * @param  array{action: 'timeline', from: string, through: string}|array{action: 'export', kinds: list<string>}  $filter
     * @param  array{date: string, kind: 'session'|'nutrition', id: int}|array{kind: int, id: int}  $position
     */
    private function cursor(int $ownerId, array $filter, array $position): string
    {
        return Crypt::encryptString(json_encode([
            'owner' => $ownerId, 'filter' => $filter, 'position' => $position,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{action: 'timeline', from: string, through: string}|array{action: 'export', kinds: list<string>}  $filter
     * @return array{date: string, kind: 'session'|'nutrition', id: int}|array{kind: int, id: int}|null
     */
    private function readCursor(?string $cursor, int $ownerId, array $filter): ?array
    {
        if (! $cursor) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new TrainingNutritionException('invalid_input', 'Invalid cursor.');
        }

        if (! is_array($decoded)
            || ($decoded['owner'] ?? null) !== $ownerId
            || ($decoded['filter'] ?? null) !== $filter
            || ! $this->validCursorPosition($filter['action'], $decoded['position'] ?? null)) {
            throw new TrainingNutritionException('invalid_input', 'Cursor does not match this query.');
        }

        return $decoded['position'];
    }

    private function validCursorPosition(string $action, mixed $position): bool
    {
        if (! is_array($position) || ! is_int($position['id'] ?? null) || $position['id'] < 0) {
            return false;
        }

        return match ($action) {
            'timeline' => is_string($position['date'] ?? null)
                && in_array($position['kind'] ?? null, ['session', 'nutrition'], true),
            'export' => is_int($position['kind'] ?? null) && $position['kind'] >= 0,
            default => false,
        };
    }

    private function createDeletionPreview(DeletePreview $data, int $ownerId): array
    {
        $selection = [];
        foreach ($data->targets as $target) {
            $record = $this->ownerRecords($target->kind, $ownerId)->findOrFail($target->id);
            $version = $this->recordVersion($target->kind, $record);
            $selection[$version->key()] = $version;
        }

        if ($data->datedScope !== null) {
            foreach ($data->datedScope->kinds as $kind) {
                $column = $kind === RecordKind::Session ? 'session_day' : 'observed_date';
                $query = $this->ownerRecords($kind, $ownerId)
                    ->whereDate($column, '>=', $data->datedScope->from)
                    ->whereDate($column, '<=', $data->datedScope->through);
                if ($query->count() > 100) {
                    throw new TrainingNutritionException('invalid_input', 'Date scope exceeds 100 records.');
                }
                foreach ($query->get() as $record) {
                    $version = $this->recordVersion($kind, $record);
                    $selection[$version->key()] = $version;
                }
            }
        }

        $aliases = [];
        $exclusions = [];
        foreach ($selection as $item) {
            if ($item->kind !== RecordKind::Session) {
                continue;
            }
            $aliases = array_merge($aliases, TrainingSession::where('user_id', $ownerId)
                ->where('merged_into_id', $item->id)->pluck('id')->all());
            $exclusions = array_merge($exclusions, TrainingWorkoutLink::where('user_id', $ownerId)
                ->where('session_id', $item->id)->get(['oura_connection_id', 'dataset', 'source_id'])->toArray());
        }

        sort($aliases);
        usort($exclusions, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        $blocking = $this->blockingDependencies($ownerId, $selection);
        $selectedRecords = array_map(fn (RecordVersion $item): array => $item->toArray(), $selection);

        $token = bin2hex(random_bytes(32));
        $expires = now()->addMinutes(10);
        TrainingNutritionOperation::create([
            'user_id' => $ownerId, 'request_id' => 'preview:'.hash('sha256', $token),
            'action' => 'delete_preview', 'fingerprint' => hash('sha256', json_encode($selectedRecords)),
            'scope' => ['selection' => array_values($selectedRecords),
                'aliases' => array_values(array_unique($aliases)), 'exclusions' => $exclusions,
                'blocking' => $blocking],
            'expires_at' => $expires,
        ]);

        return [
            'confirmation_token' => $token,
            'expires_at' => $expires->toIso8601String(),
            'records' => array_values($selectedRecords),
            'dependent_alias_ids' => array_values(array_unique($aliases)),
            'source_exclusions' => $exclusions,
            'blocking_dependencies' => $blocking,
            'oura_history_affected' => false,
        ];
    }

    private function confirmDeletion(DeleteConfirm $data, int $ownerId): array
    {
        $preview = TrainingNutritionOperation::where('user_id', $ownerId)
            ->where('request_id', 'preview:'.hash('sha256', $data->confirmationToken))
            ->where('action', 'delete_preview')->lockForUpdate()->first();

        if (! $preview || $preview->consumed_at || ! $preview->expires_at?->isFuture()) {
            throw new TrainingNutritionException('version_conflict', 'Deletion preview expired or was used.');
        }

        $selection = [];
        foreach ($preview->scope['selection'] as $record) {
            $version = RecordVersion::fromStored($record);
            $selection[$version->key()] = $version;
        }

        if ($preview->scope['blocking'] || $this->blockingDependencies($ownerId, $selection)) {
            throw new TrainingNutritionException('version_conflict', 'Deletion dependencies require a new scope.');
        }
        foreach ($selection as $item) {
            $record = $this->ownerRecords($item->kind, $ownerId)->findOrFail($item->id);
            if ($this->recordVersion($item->kind, $record)->version !== $item->version) {
                throw new TrainingNutritionException('version_conflict', 'Selected record changed.');
            }
        }

        $currentAliases = [];
        $currentExclusions = [];
        foreach ($selection as $item) {
            if ($item->kind !== RecordKind::Session) {
                continue;
            }
            $currentAliases = array_merge($currentAliases, TrainingSession::where('user_id', $ownerId)
                ->where('merged_into_id', $item->id)->pluck('id')->all());
            $currentExclusions = array_merge($currentExclusions, TrainingWorkoutLink::where('user_id', $ownerId)
                ->where('session_id', $item->id)->get(['oura_connection_id', 'dataset', 'source_id'])->toArray());
        }
        sort($currentAliases);
        usort($currentExclusions, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        if (array_values(array_unique($currentAliases)) !== $preview->scope['aliases']
            || $currentExclusions !== $preview->scope['exclusions']) {
            throw new TrainingNutritionException('version_conflict', 'Deletion dependencies changed.');
        }

        foreach ($selection as $item) {
            if ($item->kind === RecordKind::Session) {
                TrainingWorkoutLink::where('user_id', $ownerId)->where('session_id', $item->id)
                    ->update(['session_id' => null, 'state' => 'excluded', 'method' => 'deletion']);
                TrainingSession::where('user_id', $ownerId)
                    ->where('merged_into_id', $item->id)->delete();
            }
            if ($item->kind === RecordKind::Link) {
                TrainingWorkoutLink::where('user_id', $ownerId)->whereKey($item->id)
                    ->update(['session_id' => null, 'state' => 'excluded', 'method' => 'deletion']);
            } else {
                $this->ownerRecords($item->kind, $ownerId)->whereKey($item->id)->delete();
            }
        }

        $preview->consumed_at = now();
        $preview->save();
        TrainingNutritionOperation::where('user_id', $ownerId)
            ->whereNotIn('action', ['reconcile_progress', 'delete_preview'])
            ->whereNull('consumed_at')->update(['result' => json_encode(['deleted' => true])]);

        return ['deleted' => $preview->scope['selection'], 'oura_history_affected' => false];
    }
}
