<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportsPreflight;
use Illuminate\Console\Command;

class FinanceReportsPreflight extends Command
{
    protected $signature = 'finance:reports-preflight {--format=text : text|json}';

    protected $description = 'Read-only T10 ledger report reconciliation. Fails non-zero on differences.';

    public function handle(ReportsPreflight $preflight): int
    {
        $report = $preflight->report();
        if ($this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } elseif ($report['ok']) {
            $this->info('Reports preflight: no blocking issues.');
        } else {
            $this->error('Reports preflight failed:');
            foreach ($report['issues'] as $issue) {
                $this->line(' - '.$issue);
            }
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
