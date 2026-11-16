<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class DatedDeletionScope
{
    /** @param non-empty-list<RecordKind> $kinds */
    public function __construct(public array $kinds, public string $from, public string $through) {}
}
