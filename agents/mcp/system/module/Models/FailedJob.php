<?php

namespace App\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;

class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected $guarded = [];

    public function displayName(): ?string
    {
        $payload = json_decode((string) $this->payload, true);

        return is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : null;
    }
}
