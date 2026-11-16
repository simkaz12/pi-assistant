<?php

namespace App\Modules\System\Listeners;

use App\Modules\System\Contracts\ProducesNotice;
use App\Modules\System\Enums\SystemEventKind;
use App\Modules\System\Models\SystemEvent;
use Illuminate\Support\Str;

class RecordNotice
{
    public function handle(ProducesNotice $event): void
    {
        $text = $event->noticeText();

        SystemEvent::query()->create([
            'kind' => SystemEventKind::Notice,
            'title' => Str::limit(Str::before($text, "\n"), 250, ''),
            'body' => $text,
            'occurred_at' => now(),
        ]);
    }
}
