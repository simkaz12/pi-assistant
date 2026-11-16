<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class NutritionItem
{
    public function __construct(
        public string $name,
        public string $kind,
        public ?NutritionAmount $amount,
        public ?NutritionLabel $label,
    ) {}

    public static function fromValidated(array $item): self
    {
        $amount = $item['amount'] ?? null;
        $label = $item['label'] ?? null;

        return new self(
            $item['name'],
            $item['kind'],
            $amount === null ? null : new NutritionAmount(
                $amount['value'] ?? null,
                $amount['unit'] ?? null,
                $amount['basis'] ?? null,
                $amount['source'] ?? null,
            ),
            $label === null ? null : new NutritionLabel(
                $label['value'] ?? null,
                $label['unit'] ?? null,
                $label['basis'] ?? null,
                $label['serving_size'] ?? null,
            ),
        );
    }

    public function toArray(): array
    {
        $item = ['name' => $this->name, 'kind' => $this->kind];
        if ($this->amount !== null) {
            $item['amount'] = $this->amount->toArray();
        }
        if ($this->label !== null) {
            $item['label'] = $this->label->toArray();
        }

        return $item;
    }
}
