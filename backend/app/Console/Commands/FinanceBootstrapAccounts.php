<?php

namespace App\Console\Commands;

use App\Services\Treasury\FinancialAccountBootstrap;
use Illuminate\Console\Command;

class FinanceBootstrapAccounts extends Command
{
    protected $signature = 'finance:bootstrap-accounts {--apply : Persist missing default financial accounts}';

    protected $description = 'Dry-run (default) or create missing default financial accounts. Never overwrites existing defaults.';

    public function handle(FinancialAccountBootstrap $bootstrap): int
    {
        $apply = (bool) $this->option('apply');
        if (!$apply) {
            $this->warn('Dry-run. Pass --apply to write. Do not run --apply against production from this command without operator review.');
        }

        $report = $bootstrap->run($apply);
        $this->table(
            ['action', 'code', 'branch_id', 'currency', 'type', 'message'],
            array_map(fn (array $row) => [
                $row['action'],
                $row['code'],
                $row['branch_id'] ?? 'corp',
                $row['currency'],
                $row['type'],
                $row['message'],
            ], $report['planned'])
        );
        $this->info('created=' . $report['created'] . ' skipped=' . $report['skipped'] . ' warnings=' . count($report['warnings']));
        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        return $report['warnings'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
