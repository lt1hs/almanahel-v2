<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_08_15_200001 may have already run on MySQL before legacy_* columns
 * were added to that file. Laravel will not re-run it; patch here.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('stock_lots')) {
            Schema::table('stock_lots', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_lots', 'legacy_inventory_id')) {
                    $table->unsignedBigInteger('legacy_inventory_id')->nullable()->unique();
                }
                if (!Schema::hasColumn('stock_lots', 'migration_source')) {
                    $table->string('migration_source', 40)->nullable();
                }
            });
        }

        if (!Schema::hasTable('legacy_inventory_snapshots')) {
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
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_lots')) {
            Schema::table('stock_lots', function (Blueprint $table) {
                if (Schema::hasColumn('stock_lots', 'legacy_inventory_id')) {
                    $table->dropUnique(['legacy_inventory_id']);
                    $table->dropColumn('legacy_inventory_id');
                }
                if (Schema::hasColumn('stock_lots', 'migration_source')) {
                    $table->dropColumn('migration_source');
                }
            });
        }

        Schema::dropIfExists('legacy_inventory_snapshots');
    }
};
