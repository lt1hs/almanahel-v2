<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('inventories') && !Schema::hasColumn('inventories', 'superseded_by_inventory_id')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->unsignedBigInteger('superseded_by_inventory_id')->nullable();
            });
        }

        if (!Schema::hasTable('gift_lot_allocations')) {
            Schema::create('gift_lot_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gift_id')->constrained()->restrictOnDelete();
                $table->foreignId('stock_lot_id')->constrained('stock_lots')->restrictOnDelete();
                $table->unsignedInteger('quantity');
                $table->decimal('unit_cost', 15, 2);
                $table->enum('currency', ['toman', 'dinar']);
                $table->enum('ownership_type', ['owned', 'consignment']);
                $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('settled_publisher_amount', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('consignment_return_lot_allocations')) {
            Schema::create('consignment_return_lot_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('consignment_return_item_id');
                $table->foreign('consignment_return_item_id', 'crla_item_fk')
                    ->references('id')
                    ->on('consignment_return_items')
                    ->restrictOnDelete();
                $table->foreignId('stock_lot_id')->constrained('stock_lots')->restrictOnDelete();
                $table->unsignedInteger('quantity');
                $table->decimal('unit_cost', 15, 2);
                $table->enum('currency', ['toman', 'dinar']);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('settlement_allocations') && !Schema::hasColumn('settlement_allocations', 'sale_lot_allocation_id')) {
            Schema::table('settlement_allocations', function (Blueprint $table) {
                $table->unsignedBigInteger('consignment_receipt_item_id')->nullable();
                $table->unsignedBigInteger('sale_lot_allocation_id')->nullable();
                $table->unsignedBigInteger('gift_lot_allocation_id')->nullable();
                $table->unsignedInteger('quantity')->nullable();
                $table->decimal('unit_cost', 15, 2)->nullable();
            });
        }

        if (Schema::hasTable('journal_entries') && !Schema::hasColumn('journal_entries', 'event_type')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->string('event_type', 64)->default('posted');
                $table->string('currency', 16)->nullable();
            });
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->unique(['source_type', 'source_id', 'event_type'], 'journal_source_event_unique');
            });
        }

        if (Schema::hasTable('branches') && !Schema::hasColumn('branches', 'can_receive_inventory')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->boolean('can_receive_inventory')->default(true);
                $table->boolean('can_set_pricing')->default(true);
                $table->boolean('can_sell')->default(true);
                $table->boolean('can_transfer')->default(true);
                $table->boolean('can_report')->default(true);
                $table->boolean('can_manage_alerts')->default(true);
            });
            if (Schema::hasColumn('branches', 'type')) {
                DB::table('branches')->where('type', 'warehouse')->update(['can_sell' => false]);
            }
        }

        if (!Schema::hasTable('book_branch_prices')) {
            Schema::create('book_branch_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('book_id')->constrained()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->decimal('price_toman', 15, 2)->nullable();
                $table->decimal('price_dinar', 15, 2)->nullable();
                $table->timestamps();
                $table->unique(['book_id', 'branch_id']);
            });
        }

        if (Schema::hasTable('customers') && !Schema::hasColumn('customers', 'archived_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
            });
        }

        if (Schema::hasTable('expenses') && !Schema::hasColumn('expenses', 'archived_at')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('reversed_at')->nullable();
            });
        }

        if (!Schema::hasTable('stock_adjustments')) {
            Schema::create('stock_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('book_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->integer('quantity_delta');
                $table->string('reason', 255);
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('sale_lot_allocations') && !Schema::hasColumn('sale_lot_allocations', 'settled_publisher_amount')) {
            Schema::table('sale_lot_allocations', function (Blueprint $table) {
                $table->decimal('settled_publisher_amount', 15, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('book_branch_prices');
        Schema::dropIfExists('consignment_return_lot_allocations');
        Schema::dropIfExists('gift_lot_allocations');
    }
};
