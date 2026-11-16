<?php

namespace App\Modules\Oura\Services;

use RuntimeException;

class OuraRetryLater extends RuntimeException
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct('Oura requested a later retry.');
    }
}
