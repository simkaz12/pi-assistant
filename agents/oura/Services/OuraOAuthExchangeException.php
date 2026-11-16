<?php

namespace App\Modules\Oura\Services;

use RuntimeException;

class OuraOAuthExchangeException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Oura authorization exchange failed.');
    }
}
