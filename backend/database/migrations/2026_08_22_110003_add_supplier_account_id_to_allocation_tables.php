<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'sale_lot_allocations',
            'gift_lot_allocations',
            'customer_return_lot_allocations',
            'consignment_return_lot_allocations',
            'settlement_allocations',
        ] as $table) {
            $this->addColumn($table);
        }
    }

    public function down(): void
    {
        foreach ([
            'settlement_allocations',
            'consignment_return_lot_allocations',
            'customer_return_lot_allocations',
            'gift_lot_allocations',
            'sale_lot_allocations',
        ] as $table) {
            $this->dropColumn($table);
        }
    }

    private function addColumn(string $table): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, 'supplier_account_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('supplier_account_id')
                ->nullable()
                ->constrained('supplier_accounts')
                ->restrictOnDelete();
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
