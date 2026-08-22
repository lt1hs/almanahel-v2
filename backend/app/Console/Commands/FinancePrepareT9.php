<?php

namespace App\Console\Commands;

use App\Services\Finance\PrepareT9;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class FinancePrepareT9 extends Command
{
    protected $signature = 'finance:prepare-t9
        {--apply : Persist stamps. Default is dry-run with zero writes.}
        {--format=text : text|json}
        {--output= : Optional JSON report path}';

    protected $description = 'Idempotent T9 payable/date preparation. Dry-run by default. Do not --apply on production without review.';

    public function handle(PrepareT9 $prepare): int
    {
        $apply = (bool) $this->option('apply');
        if (!$apply) {
            $this->warn('Dry-run. Pass --apply to write. Do not run --apply against production from this task.');
        }

        $report = $prepare->run($apply);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($this->option('format') === 'json') {
            $this->line($json);
        } else {
            $this->info('dates invoices=' . $report['dates']['invoices'] . ' returns=' . $report['dates']['returns'] . ' settlements=' . $report['dates']['settlements']);
            $this->info('receipts=' . $report['receipts_stamped'] . ' lots=' . $report['lots_stamped'] . ' sales=' . $report['sale_allocations_stamped'] . ' gifts=' . $report['gift_allocations_stamped']);
            $this->info('writes=' . $report['writes'] . ' blockers=' . $report['blocker_count']);
            foreach ($report['ambiguous'] as $row) {
                $this->error(' - ' . $row);
            }
        }

        $output = $this->option('output');
        if (is_string($output) && $output !== '') {
            File::put($output, $json);
            $this->info('Wrote ' . $output);
        }

        return $report['blocker_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
