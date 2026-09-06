<?php

namespace App\Console\Commands;

use App\Services\Pricing\PriceBackfill;
use Illuminate\Console\Command;

class PricesBackfill extends Command
{
    protected $signature = 'prices:backfill {--apply : Persist backfill rows} {--json : JSON output}';

    protected $description = 'Backfill book_branch_prices and consignment payable_unit_cost (dry-run by default)';

    public function handle(PriceBackfill $backfill): int
    {
        $apply = (bool) $this->option('apply');
        $report = $backfill->run($apply);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($apply ? 'Apply complete.' : 'Dry run (no writes).');
        $this->table(
            ['Metric', 'Count'],
            [
                ['conflicts', $report['conflict_count']],
                ['selling would create', $report['selling_would_create']],
                ['selling created', $report['selling_created']],
                ['selling skipped conflict', $report['selling_skipped_conflict']],
                ['selling skipped existing', $report['selling_skipped_existing']],
                ['consignment lots would fill', $report['consignment_lots_would_fill']],
                ['consignment lots filled', $report['consignment_lots_filled']],
            ]
        );
        if ($report['conflicts']) {
            $this->warn('Conflicts were not auto-resolved:');
            foreach (array_slice($report['conflicts'], 0, 20) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
            }
        }
        if (!$apply) {
            $this->comment('Re-run with --apply after reviewing conflicts.');
        }

        return self::SUCCESS;
    }
}
