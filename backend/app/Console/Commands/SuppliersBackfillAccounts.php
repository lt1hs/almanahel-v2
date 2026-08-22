<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierAccountBackfill;
use Illuminate\Console\Command;

class SuppliersBackfillAccounts extends Command
{
    protected $signature = 'suppliers:backfill-accounts
        {--dry-run : Report only (default)}
        {--apply : Create accounts and stamp unambiguous rows}
        {--strict : Exit non-zero when unresolved supplier-dimensional rows remain}
        {--json : Print the integrity report as JSON}';

    protected $description = 'Backfill supplier_accounts from (branch_id, supplier_id). Never invents provenance or balances. Production rollback is a pre-migrate MySQL dump.';

    public function handle(SupplierAccountBackfill $backfill): int
    {
        $apply = (bool) $this->option('apply');
        if (!$apply) {
            $this->warn('Dry-run. Pass --apply to write. Unresolved rows stay null.');
        }

        $report = $backfill->run($apply);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('accounts_planned='.$report['accounts_planned'].' accounts_created='.$report['accounts_created']);
            $this->info('missing_supplier_id='.$report['missing_supplier_id']
                .' missing_branch_id='.$report['missing_branch_id']
                .' missing_account='.$report['missing_account']
                .' ambiguous_account='.$report['ambiguous_account']
                .' corporate_rows='.$report['corporate_rows']
                .' expected_null_owned='.$report['expected_null_owned']
                .' unresolved_pair='.$report['unresolved_pair']);
            $rows = [];
            foreach ($report['stamped'] as $table => $count) {
                if (is_array($count) && array_key_exists('before', $count)) {
                    $rows[] = [$table, $count['before'], $count['stamped'], $count['after']];
                } else {
                    $rows[] = [$table, is_array($count) ? ($count['eligible'] ?? json_encode($count)) : $count, '—', '—'];
                }
            }
            $this->table(['table', 'before_or_eligible', 'stamped', 'after'], $rows);
            if (!empty($report['leftover_null_supplier_account_id'])) {
                $this->warn('Leftover null supplier_account_id:');
                foreach ($report['leftover_null_supplier_account_id'] as $table => $n) {
                    $this->line("  {$table}: {$n}");
                }
            }
        }

        if ($this->option('strict') && $backfill->hasUnexpectedUnresolved($report)) {
            $this->error('Strict mode: unresolved supplier-dimensional rows remain.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
