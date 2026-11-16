<?php

namespace App\Modules\TrainingNutrition\Services;

use App\Modules\TrainingNutrition\Data\PageQuery;
use App\Modules\TrainingNutrition\Data\PlanAccept;
use App\Modules\TrainingNutrition\Data\PlanGet;
use App\Modules\TrainingNutrition\Data\PlanProgress;
use App\Modules\TrainingNutrition\Data\PlanSave;
use App\Modules\TrainingNutrition\Data\PlanScheduleSlot;
use App\Modules\TrainingNutrition\Models\TrainingExercise;
use App\Modules\TrainingNutrition\Models\TrainingPlan;
use App\Modules\TrainingNutrition\Models\TrainingProfile;
use App\Modules\TrainingNutrition\Resources\TrainingPlanResource;

class TrainingPlanService
{
    public function __construct(private TrainingMutationService $mutations) {}

    public function run(PlanSave|PlanGet|PageQuery|PlanAccept|PlanProgress $input, array $payload, int $ownerId): array
    {
        return match ($input::class) {
            PlanSave::class => $this->mutations->mutate(
                $ownerId, 'training_plan.save', $payload,
                fn (): array => $this->persistProposal($input, $ownerId),
            ),
            PlanGet::class => $this->get($input, $ownerId),
            PageQuery::class => $this->history($input, $ownerId),
            PlanAccept::class, PlanProgress::class => $this->mutations->mutate(
                $ownerId, 'training_plan.activate', $payload,
                fn (): array => $this->activatePlan($input, $ownerId),
            ),
        };
    }

    private function get(PlanGet $input, int $ownerId): array
    {
        $profile = TrainingProfile::where('user_id', $ownerId)->first();
        $id = $input->planId ?? $profile?->active_plan_id;

        return [
            'plan' => $id ? TrainingPlanResource::make(TrainingPlan::forOwner($ownerId)->findOrFail($id))->resolve() : null,
            'active_plan_id' => $profile?->active_plan_id,
        ];
    }

    private function history(PageQuery $input, int $ownerId): array
    {
        $limit = $input->limit;
        $afterId = $this->mutations->readPageCursor($input->cursor, $ownerId,
            ['action' => 'history', 'kind' => 'plan']);
        $records = TrainingPlan::where('user_id', $ownerId)
            ->where('id', '>', $afterId)
            ->orderBy('id')->limit($limit + 1)->get();

        return [
            'plans' => TrainingPlanResource::collection($records->take($limit))->resolve(),
            'next_cursor' => $records->count() > $limit
                ? $this->mutations->pageCursor($ownerId, ['action' => 'history', 'kind' => 'plan'],
                    $records[$limit - 1]->id)
                : null,
        ];
    }

    private function checkRuleChange(TrainingProfile $profile, TrainingPlan $next, string $ruleId, int $ownerId): void
    {
        if (! $profile->active_plan_id || $next->prior_plan_id !== $profile->active_plan_id) {
            throw new TrainingNutritionException('invalid_input', 'Rule progression must revise the active plan.');
        }

        $current = TrainingPlan::forOwner($ownerId)->findOrFail($profile->active_plan_id);
        $ruleIds = array_column($current->progression_rules ?? [], 'rule_id');

        if (! in_array($ruleId, $ruleIds, true)
            || $next->direction !== $current->direction
            || $next->progression_rules !== $current->progression_rules
            || $this->schedule($next->slots) != $this->schedule($current->slots)) {
            throw new TrainingNutritionException('invalid_input', 'Progression changes goals, schedule, or rules.');
        }
    }

    /** @return list<PlanScheduleSlot> */
    private function schedule(array $slots): array
    {
        return array_map(PlanScheduleSlot::fromStored(...), $slots);
    }

    private function persistProposal(PlanSave $data, int $ownerId): array
    {
        if ($data->priorPlanId !== null) {
            TrainingPlan::forOwner($ownerId)->findOrFail($data->priorPlanId);
        }

        $slots = [];
        foreach ($data->slots as $slot) {
            $prescriptions = [];
            foreach ($slot->exercises as $exercise) {
                $catalogue = TrainingExercise::forOwner($ownerId)->findOrFail($exercise->exerciseId);
                $prescriptions[] = $exercise->toArray($catalogue->only('id', 'name', 'measures'));
            }

            $slots[] = $slot->toArray($prescriptions);
        }

        $plan = TrainingPlan::create([
            'user_id' => $ownerId,
            'prior_plan_id' => $data->priorPlanId,
            'title' => $data->title,
            'direction' => $data->direction->toArray(),
            'slots' => $slots,
            'progression_rules' => array_map(fn ($rule): array => $rule->toArray(), $data->progressionRules),
            'rationale' => $data->rationale,
            'evidence' => array_map(fn ($item): array => $item->toArray(), $data->evidence),
        ]);

        return ['plan' => TrainingPlanResource::make($plan)->resolve(), 'status' => 'proposal'];
    }

    private function activatePlan(PlanAccept|PlanProgress $data, int $ownerId): array
    {
        $profile = TrainingProfile::where('user_id', $ownerId)->lockForUpdate()->first();

        if (! $profile) {
            throw new TrainingNutritionException('not_found', 'Create a profile before activating a plan.');
        }

        if ($profile->active_plan_id !== $data->expectedActivePlanId) {
            throw new TrainingNutritionException('version_conflict', 'Active plan changed.');
        }

        $plan = TrainingPlan::forOwner($ownerId)->findOrFail($data->planId);

        if ($data instanceof PlanProgress) {
            $this->checkRuleChange($profile, $plan, $data->ruleId, $ownerId);
        }

        $profile->active_plan_id = $plan->id;
        $profile->version++;
        $profile->save();
        $plan->activation_basis = $data instanceof PlanProgress ? 'accepted_rule' : 'user_acceptance';
        $plan->activation_reference = $data->authorizationReference;
        $plan->activated_at = now();
        $plan->save();

        return ['plan' => TrainingPlanResource::make($plan)->resolve(), 'active_plan_id' => $plan->id];
    }
}
