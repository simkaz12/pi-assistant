<?php

namespace App\Modules\System\Data;

use App\Modules\System\Enums\Severity;

final readonly class Finding
{
    public function __construct(
        public string $key,
        public Severity $severity,
        public string $title,
        public string $detail,
    ) {}
}
