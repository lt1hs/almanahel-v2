<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $snapshot = function (Blueprint $table): void {
            $table->string('payable_basis', 32)->nullable();
            $table->decimal('payable_rate', 8, 4)->nullable();
            $table->decimal('gross_cost', 18, 2)->nullable();
            $table->decimal('publisher_payable', 18, 2)->nullable();
            $table->string('rule_source', 32)->nullable();
            $table->timestamp('rule_stamped_at')->nullable();
        };

        if (Schema::hasTable('sale_lot_allocations') && !Schema::hasColumn('sale_lot_allocations', 'payable_basis')) {
            Schema::table('sale_lot_allocations', $snapshot);
        }
        if (Schema::hasTable('gift_lot_allocations') && !Schema::hasColumn('gift_lot_allocations', 'payable_basis')) {
            Schema::table('gift_lot_allocations', $snapshot);
        }
        if (Schema::hasTable('settlement_allocations') && !Schema::hasColumn('settlement_allocations', 'payable_basis')) {
            Schema::table('settlement_allocations', function (Blueprint $table) {
                $table->string('payable_basis', 32)->nullable();
                $table->decimal('payable_rate', 8, 4)->nullable();
                $table->decimal('gross_cost', 18, 2)->nullable();
                $table->decimal('publisher_payable', 18, 2)->nullable();
                $table->string('rule_source', 32)->nullable();
                $table->timestamp('rule_stamped_at')->nullable();
            });
        }

        $lotStamp = function (Blueprint $table): void {
            $table->string('payable_basis', 32)->nullable();
            $table->decimal('payable_rate', 8, 4)->nullable();
            $table->string('payable_rule_source', 32)->nullable();
            $table->timestamp('payable_rule_stamped_at')->nullable();
        };

        if (Schema::hasTable('stock_lots') && !Schema::hasColumn('stock_lots', 'payable_basis')) {
            Schema::table('stock_lots', $lotStamp);
        }
        if (Schema::hasTable('consignment_receipts') && !Schema::hasColumn('consignment_receipts', 'payable_basis')) {
            Schema::table('consignment_receipts', $lotStamp);
        }
    }

    public function down(): void
    {
        $allocCols = ['payable_basis', 'payable_rate', 'gross_cost', 'publisher_payable', 'rule_source', 'rule_stamped_at'];
        $lotCols = ['payable_basis', 'payable_rate', 'payable_rule_source', 'payable_rule_stamped_at'];

        foreach (['sale_lot_allocations', 'gift_lot_allocations', 'settlement_allocations'] as $table) {
            $this->dropColumns($table, $allocCols);
        }
        foreach (['stock_lots', 'consignment_receipts'] as $table) {
            $this->dropColumns($table, $lotCols);
        }
    }

    /** @param list<string> $columns */
    private function dropColumns(string $table, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        $existing = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
        if ($existing === []) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($existing) {
            $blueprint->dropColumn($existing);
        });
    }
};
