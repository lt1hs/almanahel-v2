<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-local supplier accounts. No stored balance column.
 * Uniques are MySQL/SQLite compatible (nullable columns allow multiple NULLs).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('supplier_accounts')) {
            return;
        }

        Schema::create('supplier_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('display_name');
            $table->string('local_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('type', 32)->default('publisher');
            $table->string('payment_terms')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['branch_id', 'supplier_id'], 'supplier_accounts_branch_supplier_unique');
            $table->unique(['branch_id', 'local_code'], 'supplier_accounts_branch_local_code_unique');
            $table->index(['branch_id', 'status']);
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_accounts');
    }
};
