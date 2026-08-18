<?php

namespace App\Console\Commands;

use App\Services\Notifications\AlertInbox;
use Illuminate\Console\Command;

class GenerateAlerts extends Command
{
    protected $signature = 'alerts:generate';
    protected $description = 'Persist low-stock, check-due, credit-due, and transfer notifications (deduped)';

    public function handle(AlertInbox $inbox): int
    {
        $created = $inbox->sync();
        $this->info("Upserted {$created} notifications");

        return self::SUCCESS;
    }
}
