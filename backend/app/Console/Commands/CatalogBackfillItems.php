<?php

namespace App\Console\Commands;

use App\Services\Catalog\BranchCatalogBackfill;
use Illuminate\Console\Command;

class CatalogBackfillItems extends Command
{
    protected $signature = 'catalog:backfill-items {--apply : Persist catalog rows} {--json : JSON output}';

    protected $description = 'Backfill branch_catalog_items from operational rows (never guesses central/local from branch type)';

    public function handle(BranchCatalogBackfill $backfill): int
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
            collect($report)
                ->except('by_source')
                ->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])
                ->values()
                ->all()
        );

        if (!empty($report['by_source'])) {
            $this->info('By source:');
            foreach ($report['by_source'] as $source => $count) {
                $this->line("  {$source}: {$count}");
            }
        }

        if (!$apply) {
            $this->comment('Re-run with --apply to create missing catalog rows.');
        }

        return self::SUCCESS;
    }
}
