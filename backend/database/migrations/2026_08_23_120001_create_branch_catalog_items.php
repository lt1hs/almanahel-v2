<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_catalog_items')) {
            return;
        }

        Schema::create('branch_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();
            $table->string('source', 32)->default('legacy_unknown');
            $table->foreignId('local_supplier_account_id')
                ->nullable()
                ->constrained('supplier_accounts')
                ->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->decimal('price_toman', 18, 2)->nullable();
            $table->decimal('price_dinar', 18, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'book_id'], 'branch_catalog_branch_book_unique');
            $table->index(['branch_id', 'source'], 'branch_catalog_branch_source_idx');
            $table->index(['branch_id', 'active'], 'branch_catalog_branch_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_catalog_items');
    }
};
