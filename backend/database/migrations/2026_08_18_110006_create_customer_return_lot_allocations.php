<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('customer_return_lot_allocations')) {
            return;
        }

        Schema::create('customer_return_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_return_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_lot_allocation_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 18, 2);
            $table->enum('currency', ['toman', 'dinar']);
            $table->string('ownership_type', 32);
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payable_basis', 32)->nullable();
            $table->decimal('payable_rate', 8, 4)->nullable();
            $table->decimal('publisher_payable_reversed', 18, 2)->default(0);
            $table->decimal('unsettled_payable_reversed', 18, 2)->default(0);
            $table->decimal('settled_payable_reversed', 18, 2)->default(0);
            $table->string('origin_scope', 32)->nullable();
            $table->timestamps();

            $table->index(['customer_return_id']);
            $table->index(['sale_lot_allocation_id']);
        });

        if (Schema::hasTable('journal_lines') && !Schema::hasColumn('journal_lines', 'customer_return_lot_allocation_id')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->foreignId('customer_return_lot_allocation_id')
                    ->nullable()
                    ->constrained('customer_return_lot_allocations')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('journal_lines') && Schema::hasColumn('journal_lines', 'customer_return_lot_allocation_id')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->dropConstrainedForeignId('customer_return_lot_allocation_id');
            });
        }
        Schema::dropIfExists('customer_return_lot_allocations');
    }
};
