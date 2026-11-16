<?php

namespace App\Modules\TrainingNutrition\Services;

use App\Modules\TrainingNutrition\Data\ExerciseSave;
use App\Modules\TrainingNutrition\Data\PageQuery;
use App\Modules\TrainingNutrition\Data\ProfileGet;
use App\Modules\TrainingNutrition\Data\ProfileUpdate;
use App\Modules\TrainingNutrition\Models\TrainingExercise;
use App\Modules\TrainingNutrition\Models\TrainingProfile;
use App\Modules\TrainingNutrition\Resources\TrainingExerciseResource;
use App\Modules\TrainingNutrition\Resources\TrainingProfileResource;

class TrainingProfileService
{
    public function __construct(private TrainingMutationService $mutations) {}

    public function run(ProfileGet|PageQuery|ProfileUpdate|ExerciseSave $input, array $payload, int $ownerId): array
    {
        return match ($input::class) {
            ProfileGet::class => $this->get($ownerId),
            ProfileUpdate::class => $this->mutations->mutate(
                $ownerId, 'training_profile.update', $payload,
                fn (): array => $this->saveProfile($input, $ownerId),
            ),
            PageQuery::class => $this->exercises($input, $ownerId),
            ExerciseSave::class => $this->mutations->mutate(
                $ownerId, 'training_profile.save_exercise', $payload,
                fn (): array => $this->persistExercise($input, $ownerId),
            ),
        };
    }

    private function get(int $ownerId): array
    {
        $profile = TrainingProfile::where('user_id', $ownerId)->first();

        return ['profile' => $profile ? TrainingProfileResource::make($profile)->resolve() : null, 'missing' => $profile === null];
    }

    private function exercises(PageQuery $input, int $ownerId): array
    {
        $afterId = $this->mutations->readPageCursor($input->cursor, $ownerId,
            ['action' => 'exercises']);
        $records = TrainingExercise::where('user_id', $ownerId)
            ->where('id', '>', $afterId)
            ->orderBy('id')->limit($input->limit + 1)->get();
        $limit = $input->limit;

        return [
            'exercises' => TrainingExerciseResource::collection($records->take($limit))->resolve(),
            'next_cursor' => $records->count() > $limit
                ? $this->mutations->pageCursor($ownerId, ['action' => 'exercises'], $records[$limit - 1]->id)
                : null,
        ];
    }

    private function saveProfile(ProfileUpdate $data, int $ownerId): array
    {
        $profile = TrainingProfile::where('user_id', $ownerId)->lockForUpdate()->first();

        if (! $profile && $data->expectedVersion !== 0) {
            throw new TrainingNutritionException('version_conflict', 'Profile does not exist.');
        }

        if (! $profile) {
            $profile = TrainingProfile::create([
                'user_id' => $ownerId,
                'timezone' => $data->timezone ?? config('training_nutrition.timezone'),
                'goals' => $data->goals ?? [],
                'constraints' => $data->constraints ?? [],
                'preferences' => $data->preferences ?? [],
                'locations' => $data->locations ?? [],
            ]);

            return ['profile' => TrainingProfileResource::make($profile->fresh())->resolve()];
        }

        if ($profile->version !== $data->expectedVersion) {
            throw new TrainingNutritionException('version_conflict', 'Record version changed.');
        }

        $profile->fill(array_filter([
            'timezone' => $data->timezone,
            'goals' => $data->goals,
            'constraints' => $data->constraints,
            'preferences' => $data->preferences,
            'locations' => $data->locations,
        ], fn ($value) => $value !== null));
        $profile->version++;
        $profile->save();

        return ['profile' => TrainingProfileResource::make($profile->fresh())->resolve()];
    }

    private function persistExercise(ExerciseSave $data, int $ownerId): array
    {
        if ($data->exerciseId === null) {
            if ($data->expectedVersion !== 0) {
                throw new TrainingNutritionException('version_conflict', 'New exercise requires version zero.');
            }

            $exercise = TrainingExercise::create([
                'user_id' => $ownerId,
                'name' => $data->name,
                'aliases' => $data->aliases ?? [],
                'metadata' => $data->metadata ?? [],
                'measures' => $data->measures,
            ]);

            return ['exercise' => TrainingExerciseResource::make($exercise)->resolve()];
        }

        $exercise = TrainingExercise::forOwner($ownerId)->findOrFail($data->exerciseId);
        if ($exercise->version !== $data->expectedVersion) {
            throw new TrainingNutritionException('version_conflict', 'Record version changed.');
        }

        $exercise->fill(array_filter([
            'name' => $data->name,
            'aliases' => $data->aliases,
            'metadata' => $data->metadata,
            'measures' => $data->measures,
        ], fn ($value) => $value !== null));
        $exercise->version++;
        $exercise->save();

        return ['exercise' => TrainingExerciseResource::make($exercise->fresh())->resolve()];
    }
}
