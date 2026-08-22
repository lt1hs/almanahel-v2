<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addColumn('stock_lots', nullOnDelete: true, withBranchIndex: true);
        $this->addColumn('consignment_receipts', nullOnDelete: true, withBranchIndex: true);
        $this->addColumn('consignment_returns', nullOnDelete: true, withBranchIndex: true);
        $this->addColumn('settlements', nullOnDelete: true, withBranchIndex: true);
        $this->addColumn('gifts', nullOnDelete: true, withBranchIndex: true);
        $this->addColumn('journal_lines', nullOnDelete: false, withBranchIndex: false);
    }

    public function down(): void
    {
        foreach ([
            'journal_lines',
            'gifts',
            'settlements',
            'consignment_returns',
            'consignment_receipts',
            'stock_lots',
        ] as $table) {
            $this->dropColumn($table);
        }
    }

    private function addColumn(string $table, bool $nullOnDelete, bool $withBranchIndex): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, 'supplier_account_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($nullOnDelete, $withBranchIndex, $table) {
            $fk = $blueprint->foreignId('supplier_account_id')->nullable()->constrained('supplier_accounts');
            if ($nullOnDelete) {
                $fk->nullOnDelete();
            } else {
                $fk->restrictOnDelete();
            }
            if ($withBranchIndex && Schema::hasColumn($table, 'branch_id')) {
                $blueprint->index(['branch_id', 'supplier_account_id'], $table.'_branch_supplier_account_idx');
            }
        });
    }

    private function dropColumn(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'supplier_account_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropConstrainedForeignId('supplier_account_id');
        });
    }
};
