<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class RecordTarget
{
    public function __construct(public RecordKind $kind, public int $id) {}

    public static function fromValidated(array $target): self
    {
        return new self(RecordKind::from($target['kind']), $target['id']);
    }
}
