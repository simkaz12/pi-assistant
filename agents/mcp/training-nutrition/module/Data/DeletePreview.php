<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class DeletePreview
{
    /**
     * @param  list<RecordTarget>  $targets
     */
    public function __construct(
        public array $targets,
        public ?DatedDeletionScope $datedScope,
    ) {}
}
