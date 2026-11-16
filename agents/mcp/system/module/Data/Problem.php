<?php

namespace App\Modules\System\Data;

use App\Modules\System\Enums\Severity;
use Carbon\CarbonImmutable;

final readonly class Problem
{
    public const ALERT_AFTER_SECONDS = 300;

    public function __construct(
        public string $key,
        public Severity $severity,
        public string $title,
        public string $detail,
        public CarbonImmutable $since,
        public bool $alertable,
    ) {}

    public static function at(
        string $key,
        Severity $severity,
        string $title,
        string $detail,
        CarbonImmutable $since,
        CarbonImmutable $now,
    ): self {
        $alertable = $severity === Severity::Critical
            && $since->diffInSeconds($now, false) >= self::ALERT_AFTER_SECONDS;

        return new self($key, $severity, $title, $detail, $since, $alertable);
    }

    /**
     * @param  array{severity: string, title: string, detail: string, since: string}  $data
     */
    public static function fromStored(string $key, array $data, CarbonImmutable $now): self
    {
        return self::at(
            $key,
            Severity::from($data['severity']),
            $data['title'],
            $data['detail'],
            CarbonImmutable::parse($data['since']),
            $now,
        );
    }

    /**
     * @return array{key: string, severity: string, title: string, detail: string, since: string, alertable: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'detail' => $this->detail,
            'since' => $this->since->toIso8601String(),
            'alertable' => $this->alertable,
        ];
    }
}
