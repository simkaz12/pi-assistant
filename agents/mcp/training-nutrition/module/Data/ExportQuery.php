<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class ExportQuery
{
    /** @param list<RecordKind> $kinds */
    public function __construct(public array $kinds, public ?string $cursor, public int $limit) {}
}
