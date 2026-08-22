<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('journal_lines')) {
            return;
        }

        $addGiftAlloc = Schema::hasTable('gift_lot_allocations')
            && !Schema::hasColumn('journal_lines', 'gift_lot_allocation_id');
        $addSettlementAlloc = Schema::hasTable('settlement_allocations')
            && !Schema::hasColumn('journal_lines', 'settlement_allocation_id');

        Schema::table('journal_lines', function (Blueprint $table) use ($addGiftAlloc, $addSettlementAlloc) {
            if (!Schema::hasColumn('journal_lines', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            }
            if (!Schema::hasColumn('journal_lines', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            }
            if (!Schema::hasColumn('journal_lines', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            }
            if (!Schema::hasColumn('journal_lines', 'origin_scope')) {
                $table->string('origin_scope', 32)->nullable();
            }
            if (!Schema::hasColumn('journal_lines', 'stock_lot_id')) {
                $table->foreignId('stock_lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
            }
            if (!Schema::hasColumn('journal_lines', 'sale_lot_allocation_id')) {
                $table->foreignId('sale_lot_allocation_id')->nullable()->constrained('sale_lot_allocations')->restrictOnDelete();
            }
            if ($addGiftAlloc) {
                $table->foreignId('gift_lot_allocation_id')->nullable()->constrained('gift_lot_allocations')->restrictOnDelete();
            }
            if ($addSettlementAlloc) {
                $table->foreignId('settlement_allocation_id')->nullable()->constrained('settlement_allocations')->restrictOnDelete();
            }
            if (!Schema::hasColumn('journal_lines', 'check_id')) {
                $table->foreignId('check_id')->nullable()->constrained('checks')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('journal_lines')) {
            return;
        }

        Schema::table('journal_lines', function (Blueprint $table) {
            foreach ([
                'check_id',
                'settlement_allocation_id',
                'gift_lot_allocation_id',
                'sale_lot_allocation_id',
                'stock_lot_id',
                'customer_id',
                'supplier_id',
                'branch_id',
            ] as $col) {
                if (Schema::hasColumn('journal_lines', $col)) {
                    $table->dropConstrainedForeignId($col);
                }
            }
            if (Schema::hasColumn('journal_lines', 'origin_scope')) {
                $table->dropColumn('origin_scope');
            }
        });
    }
};
