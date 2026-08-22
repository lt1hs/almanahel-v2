<?php

namespace App\Services\Ledger;

use App\Models\CustomerReturn;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Gift;
use App\Models\Invoice;
use App\Models\LedgerAccount;
use App\Models\Settlement;
use App\Models\StockLot;
use App\Services\Treasury\FinancialAccountBootstrap;
use App\Support\Money;
use Illuminate\Support\Facades\Schema;

class T9Preflight
{
    public function __construct(
        private readonly AllocationIntegrity $allocations = new AllocationIntegrity(),
        private readonly JournalChainInspector $chains = new JournalChainInspector()
    ) {
    }

    /**
     * @return list<string>
     */
    public function issues(): array
    {
        $schema = $this->missingSchema();
        if ($schema !== []) {
            return $schema;
        }

        return array_merge(
            $this->unstampedPayables(),
            $this->financialAccountIssues(),
            $this->allocationIssues(),
            $this->payableSplitIssues(),
            $this->receiptPayableIssues(),
            $this->chains->issues(),
            $this->unbalancedJournals(),
            $this->missingSystemAccounts(),
            $this->financialLedgerMismatches(),
            $this->missingBusinessDates(),
        );
    }

    /** @return list<string> */
    private function missingSchema(): array
    {
        $issues = [];
        $tables = [
            'financial_accounts',
            'customer_return_lot_allocations',
        ];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                $issues[] = "missing table {$table} (2026_08_18 migrations not applied)";
            }
        }
        $columns = [
            'stock_lots' => ['payable_basis', 'payable_rate'],
            'sale_lot_allocations' => ['publisher_payable', 'payable_basis'],
            'journal_lines' => [
                'financial_account_id',
                'gift_lot_allocation_id',
                'settlement_allocation_id',
                'customer_return_lot_allocation_id',
                'check_id',
            ],
            'journal_entries' => ['version', 'status', 'reversed_at', 'supersedes_entry_id'],
            'customer_return_lot_allocations' => [
                'publisher_payable_reversed',
                'unsettled_payable_reversed',
                'settled_payable_reversed',
            ],
            'invoices' => ['sold_at'],
            'customer_returns' => ['returned_at', 'receivable_reduction', 'cash_refund', 'customer_credit_created', 'currency'],
            'settlements' => ['paid_at', 'cleared_at', 'bounced_at', 'cancelled_at'],
            'financial_accounts' => ['default_scope_key', 'branch_id'],
            'warehouse_logs' => ['stock_lot_id'],
            'customer_payments' => ['financial_account_id', 'idempotency_key'],
        ];
        foreach ($columns as $table => $cols) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($cols as $col) {
                if (!Schema::hasColumn($table, $col)) {
                    $issues[] = "missing column {$table}.{$col}";
                }
            }
        }
        if (Schema::hasTable('customer_return_lot_allocations')
            && Schema::hasColumn('customer_return_lot_allocations', 'was_supplier_payable_settled')) {
            $issues[] = 'obsolete column customer_return_lot_allocations.was_supplier_payable_settled';
        }

        if (Schema::hasTable('journal_entries')) {
            $indexNames = collect(Schema::getIndexes('journal_entries'))->pluck('name');
            foreach (['journal_source_event_version_unique', 'journal_entries_reverses_entry_id_unique'] as $name) {
                if (!$indexNames->contains($name)) {
                    $issues[] = "missing index {$name}";
                }
            }
        }
        if (Schema::hasTable('financial_accounts')) {
            $indexNames = collect(Schema::getIndexes('financial_accounts'))->pluck('name');
            if (!$indexNames->contains('financial_accounts_default_scope_unique')) {
                $issues[] = 'missing index financial_accounts_default_scope_unique';
            }
            $fk = collect(Schema::getForeignKeys('financial_accounts'))->first(function ($key) {
                return in_array('branch_id', $key['columns'], true);
            });
            if ($fk && strtolower((string) ($fk['on_delete'] ?? '')) === 'set null') {
                $issues[] = 'financial_accounts.branch_id must restrictOnDelete';
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function unstampedPayables(): array
    {
        $issues = [];
        $lots = StockLot::query()
            ->where('ownership_type', 'consignment')
            ->where(function ($q) {
                $q->whereNull('payable_basis')->orWhereNull('payable_rate');
            })
            ->count();
        if ($lots > 0) {
            $issues[] = "unstamped consignment lots: {$lots}";
        }
        $sales = 0;
        \App\Models\SaleLotAllocation::query()->with('lot')->orderBy('id')->chunkById(100, function ($rows) use (&$sales) {
            foreach ($rows as $row) {
                if ($row->lot && $row->lot->ownership_type === 'consignment'
                    && ($row->publisher_payable === null || $row->payable_basis === null)) {
                    $sales++;
                }
            }
        });
        if ($sales > 0) {
            $issues[] = "unstamped consignment sale allocations: {$sales}";
        }
        $gifts = \App\Models\GiftLotAllocation::query()
            ->where('ownership_type', 'consignment')
            ->where(function ($q) {
                $q->whereNull('publisher_payable')->orWhereNull('payable_basis');
            })
            ->count();
        if ($gifts > 0) {
            $issues[] = "unstamped consignment gift allocations: {$gifts}";
        }

        return $issues;
    }

    /** @return list<string> */
    private function financialAccountIssues(): array
    {
        $issues = [];
        $report = (new FinancialAccountBootstrap())->run(false);
        foreach ($report['warnings'] as $warning) {
            $issues[] = $warning;
        }
        foreach ($report['planned'] as $row) {
            if ($row['action'] === 'create_default') {
                $issues[] = "missing default financial account: {$row['code']}";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function allocationIssues(): array
    {
        $issues = [];
        Invoice::query()->orderBy('id')->chunkById(50, function ($invoices) use (&$issues) {
            foreach ($invoices as $invoice) {
                $issues = array_merge($issues, $this->allocations->saleIssues($invoice));
            }
        });
        Gift::query()->orderBy('id')->chunkById(50, function ($gifts) use (&$issues) {
            foreach ($gifts as $gift) {
                $issues = array_merge($issues, $this->allocations->giftIssues($gift));
            }
        });
        CustomerReturn::query()->orderBy('id')->chunkById(50, function ($returns) use (&$issues) {
            foreach ($returns as $return) {
                $issues = array_merge($issues, $this->allocations->returnIssues($return));
            }
        });
        Settlement::query()->orderBy('id')->chunkById(50, function ($rows) use (&$issues) {
            foreach ($rows as $settlement) {
                $issues = array_merge($issues, $this->allocations->settlementIssues($settlement));
            }
        });

        return $issues;
    }

    /** @return list<string> */
    private function payableSplitIssues(): array
    {
        return $this->allocations->payableSplitIssues();
    }

    /** @return list<string> */
    private function receiptPayableIssues(): array
    {
        $issues = [];
        $snapshots = app(\App\Services\Settlement\SnapshotPayable::class);
        \App\Models\ConsignmentReceipt::query()->with('items')->orderBy('id')->chunkById(50, function ($receipts) use (&$issues, $snapshots) {
            foreach ($receipts as $receipt) {
                $expected = $snapshots->receiptEffectiveSettled($receipt);
                if (Money::cmp($expected, $receipt->settled_amount ?? 0) !== 0) {
                    $issues[] = "receipt {$receipt->id} settled_amount != effective settlements";
                }
            }
        });

        return $issues;
    }

    /** @return list<string> */
    private function unbalancedJournals(): array
    {
        $unbalanced = 0;
        \App\Models\JournalEntry::query()->orderBy('id')->chunkById(100, function ($entries) use (&$unbalanced) {
            foreach ($entries as $entry) {
                $debit = '0.00';
                $credit = '0.00';
                foreach (\App\Models\JournalLine::where('journal_entry_id', $entry->id)->get() as $line) {
                    $debit = Money::add($debit, $line->debit);
                    $credit = Money::add($credit, $line->credit);
                    if ($line->currency !== $entry->currency) {
                        $unbalanced++;
                    }
                }
                if (Money::cmp($debit, $credit) !== 0) {
                    $unbalanced++;
                }
            }
        });
        if ($unbalanced > 0) {
            return ["unbalanced or mixed-currency journals: {$unbalanced}"];
        }

        return [];
    }

    /** @return list<string> */
    private function missingSystemAccounts(): array
    {
        $issues = [];
        $codes = [
            'owned_inventory', 'sales_revenue', 'sales_returns', 'cogs', 'operating_expense',
            'gift_expense', 'customer_credit_liability', 'supplier_recoverable', 'trade_payable',
            'cash_drawer', 'bank', 'card_clearing', 'checks_receivable', 'accounts_receivable',
            'supplier_payable', 'checks_payable',
        ];
        foreach (['toman', 'dinar'] as $currency) {
            foreach ($codes as $code) {
                if (!LedgerAccount::where('code', 'sys.' . $code . '.' . $currency)->exists()) {
                    $issues[] = 'missing system account sys.' . $code . '.' . $currency;
                }
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function financialLedgerMismatches(): array
    {
        $issues = [];
        foreach (FinancialAccount::query()->with('ledgerAccount')->get() as $account) {
            $ledger = $account->ledgerAccount;
            if (!$ledger) {
                $issues[] = "financial account {$account->code} missing ledger row";
                continue;
            }
            if ($ledger->currency && $ledger->currency !== $account->currency) {
                $issues[] = "financial account {$account->code} currency mismatch with ledger";
            }
            $expected = 'sys.' . $account->type . '.' . $account->currency;
            if ($ledger->code !== $expected) {
                $issues[] = "financial account {$account->code} ledger {$ledger->code} != {$expected}";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function missingBusinessDates(): array
    {
        $issues = [];
        $invoices = Invoice::query()->whereNull('sold_at')->count();
        if ($invoices > 0) {
            $issues[] = "invoices missing sold_at: {$invoices}";
        }
        $returns = CustomerReturn::query()->whereNull('returned_at')->count();
        if ($returns > 0) {
            $issues[] = "customer returns missing returned_at: {$returns}";
        }
        $gifts = Gift::query()->whereNull('gifted_at')->count();
        if ($gifts > 0) {
            $issues[] = "gifts missing gifted_at: {$gifts}";
        }
        $expenses = Expense::query()->whereNull('date')->count();
        if ($expenses > 0) {
            $issues[] = "expenses missing date: {$expenses}";
        }
        $settlements = Settlement::query()->whereNull('paid_at')->count();
        if ($settlements > 0) {
            $issues[] = "settlements missing paid_at: {$settlements}";
        }

        return $issues;
    }
}
