<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive lot schema. Does not delete or merge legacy inventory rows.
 * Duplicate (branch, book) rows are preserved until stock:backfill-lots snapshots them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_inventory_id')->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->string('ownership_type', 32)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('cost_price_toman', 15, 2)->nullable();
            $table->decimal('cost_price_dinar', 15, 2)->nullable();
            $table->decimal('price_toman', 15, 2)->nullable();
            $table->decimal('price_dinar', 15, 2)->nullable();
            $table->integer('quantity')->default(0);
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('consignment_receipt_item_id')->nullable()->constrained('consignment_receipt_items')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('ownership_type', ['owned', 'consignment'])->default('owned');
            $table->enum('currency', ['toman', 'dinar'])->default('toman');
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->unsignedInteger('qty_original');
            $table->unsignedInteger('qty_available')->default(0);
            $table->unsignedInteger('qty_reserved')->default(0);
            $table->string('origin', 32)->default('other');
            $table->foreignId('parent_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->boolean('legacy_uncertain')->default(false);
            $table->unsignedBigInteger('legacy_inventory_id')->nullable()->unique();
            $table->string('migration_source', 40)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'book_id', 'qty_available', 'id'], 'stock_lots_fifo_idx');
            $table->index(['consignment_receipt_item_id']);
            $table->index(['supplier_id', 'ownership_type']);
        });

        Schema::create('stock_lot_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->string('type', 40);
            $table->integer('quantity');
            $table->nullableMorphs('reference');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 15, 2);
            $table->enum('currency', ['toman', 'dinar']);
            $table->unsignedInteger('quantity_returned')->default(0);
            $table->decimal('settled_publisher_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['invoice_item_id']);
            $table->index(['stock_lot_id']);
        });

        Schema::create('transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });

        Schema::create('transfer_item_lot_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_item_id')->constrained('transfer_items')->restrictOnDelete();
            $table->foreignId('source_lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->foreignId('dest_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_item_lot_splits');
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('sale_lot_allocations');
        Schema::dropIfExists('stock_lot_movements');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('legacy_inventory_snapshots');
    }
};
