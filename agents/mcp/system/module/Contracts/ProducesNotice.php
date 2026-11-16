<?php

namespace App\Modules\System\Contracts;

interface ProducesNotice
{
    public function noticeText(): string;
}
