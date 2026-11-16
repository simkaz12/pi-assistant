<?php

namespace App\Modules\TrainingNutrition\Data;

final readonly class RecordVersion
{
    public function __construct(
        public RecordKind $kind,
        public int $id,
        public int|string $version,
    ) {}

    public static function fromStored(array $record): self
    {
        return new self(RecordKind::from($record['kind']), $record['id'], $record['version']);
    }

    public function key(): string
    {
        return $this->kind->value.':'.$this->id;
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'id' => $this->id, 'version' => $this->version];
    }
}
