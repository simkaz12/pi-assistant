<?php

namespace App\Modules\TrainingNutrition\Services;

use App\Models\User;
use App\Modules\TrainingNutrition\Models\TrainingNutritionOperation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TrainingMutationService
{
    public function mutate(int $ownerId, string $action, array $input, callable $callback): array
    {
        $fingerprint = hash('sha256', json_encode($this->sorted($input), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($ownerId, $action, $input, $fingerprint, $callback): array {
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();

            $receipt = TrainingNutritionOperation::query()
                ->where('user_id', $ownerId)
                ->where('request_id', $input['request_id'])
                ->lockForUpdate()->first();

            if ($receipt) {
                if ($receipt->fingerprint !== $fingerprint || $receipt->action !== $action) {
                    throw new TrainingNutritionException('request_conflict', 'Request ID was already used for different content.');
                }

                return $receipt->result ?? [];
            }

            $result = $callback();
            TrainingNutritionOperation::create([
                'user_id' => $ownerId,
                'request_id' => $input['request_id'],
                'action' => $action,
                'fingerprint' => $fingerprint,
                'result' => $result,
            ]);

            return $result;
        });
    }

    public function pageCursor(int $ownerId, array $filter, int $afterId): string
    {
        return Crypt::encryptString(json_encode([
            'owner' => $ownerId,
            'filter' => $filter,
            'after_id' => $afterId,
        ], JSON_THROW_ON_ERROR));
    }

    public function readPageCursor(?string $cursor, int $ownerId, array $filter): int
    {
        if ($cursor === null) {
            return 0;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new TrainingNutritionException('invalid_input', 'Invalid cursor.');
        }

        if (($decoded['owner'] ?? null) !== $ownerId
            || ($decoded['filter'] ?? null) !== $filter
            || ! is_int($decoded['after_id'] ?? null)
            || $decoded['after_id'] < 0) {
            throw new TrainingNutritionException('invalid_input', 'Cursor does not match this query.');
        }

        return $decoded['after_id'];
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->sorted(...), $value);
    }
}
