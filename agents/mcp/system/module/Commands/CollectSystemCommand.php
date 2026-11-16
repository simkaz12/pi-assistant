<?php

namespace App\Modules\System\Commands;

use App\Modules\System\Services\SystemCollector;
use Illuminate\Console\Command;

class CollectSystemCommand extends Command
{
    protected $signature = 'system:collect';

    protected $description = 'Collect the Pi health snapshot, evaluate the rules and record problem changes';

    public function handle(SystemCollector $collector): int
    {
        $snapshot = $collector->collect();

        $this->info(sprintf('Collected at %s with %d probe error(s).', $snapshot->collectedAt->toIso8601String(), count($snapshot->probeErrors)));

        return self::SUCCESS;
    }
}
