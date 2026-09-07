<?php

namespace App\Console\Commands;

use App\Services\BranchShare\BranchSalesShareService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BranchShareBackfill extends Command
{
    protected $signature = 'branch-share:backfill {--from= : Cutover datetime} {--branch= : Branch id} {--json}';

    protected $description = 'Dry-run estimate of historical branch-share snapshots. Never writes journals or invoices.';

    public function handle(BranchSalesShareService $shares): int
    {
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : now();
        $branch = $this->option('branch') ? (int) $this->option('branch') : null;
        $report = $shares->backfillPreview($from, $branch);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Dry run only. No journals, invoices, or snapshots were written.');
        $this->table(
            ['Metric', 'Value'],
            [
                ['would_snapshot_lines', $report['would_snapshot_lines']],
                ['from', $report['from']],
                ['writes_journals', 'no'],
                ['rewrites_invoices', 'no'],
            ]
        );

        return self::SUCCESS;
    }
}
