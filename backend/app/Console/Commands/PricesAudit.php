<?php

namespace App\Console\Commands;

use App\Services\Pricing\PriceBackfill;
use Illuminate\Console\Command;

class PricesAudit extends Command
{
    protected $signature = 'prices:audit {--json : JSON output}';

    protected $description = 'Report selling-price source conflicts and consignment lots missing payable_unit_cost';

    public function handle(PriceBackfill $backfill): int
    {
        $report = $backfill->audit();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Price audit (read-only).');
        $this->table(
            ['Metric', 'Count'],
            [
                ['conflicts', $report['conflict_count']],
                ['selling skipped conflict', $report['selling_skipped_conflict']],
                ['consignment lots missing payable_unit_cost', $report['consignment_lots_would_fill']],
            ]
        );
        if ($report['conflicts']) {
            $this->warn('Conflicts (first 20):');
            foreach (array_slice($report['conflicts'], 0, 20) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }
}
