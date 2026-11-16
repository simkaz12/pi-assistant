<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class PageQuery
{
    public function __construct(public ?string $cursor, public int $limit) {}
}
