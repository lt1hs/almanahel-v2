<?php

namespace App\Console\Commands;

use App\Services\Stock\LegacyLotBackfill;
use Illuminate\Console\Command;

class BackfillStockLots extends Command
{
    protected $signature = 'stock:backfill-lots {--dry-run : Report only, make no changes}';
    protected $description = 'Snapshot each legacy inventory row and create idempotent stock lots';

    public function handle(LegacyLotBackfill $backfill): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = $dry ? $backfill->analyze() : $backfill->run(false);
        if ($dry) {
            $report['dry_run'] = true;
        }

        $this->table(
            ['Metric', 'Value'],
            collect($report)
                ->except(['before', 'after', 'groups'])
                ->map(fn ($v, $k) => [$k, is_scalar($v) ? $v : json_encode($v)])
                ->values()
                ->all()
        );

        if (!empty($report['before'])) {
            $this->info('Before totals (branch/book/type/supplier/qty): ' . count($report['before'] ?? []));
        }
        if (!empty($report['after'])) {
            $this->info('After totals: ' . count($report['after']));
        }

        $this->info($dry ? 'Dry run complete — no rows were written.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}
