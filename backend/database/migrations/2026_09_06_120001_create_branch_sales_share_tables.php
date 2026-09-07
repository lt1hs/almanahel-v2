<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('branch_sales_share_batches')) {
            Schema::create('branch_sales_share_batches', function (Blueprint $table) {
                $table->id();
                $table->string('scope', 32);
                $table->string('status', 32)->default('draft');
                $table->unsignedInteger('rate_bps');
                $table->string('calculation_basis', 32)->default('net_realized_sales');
                $table->string('reason', 255);
                $table->dateTime('effective_from');
                $table->timestamp('applied_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('idempotency_key', 80)->unique();
                $table->string('request_hash', 64);
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['status', 'effective_from'], 'bssb_status_from_idx');
            });
        }

        if (!Schema::hasTable('branch_sales_share_rules')) {
            Schema::create('branch_sales_share_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->nullable()->constrained('branch_sales_share_batches')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
                $table->string('scope_key', 32);
                $table->unsignedInteger('rate_bps');
                $table->string('calculation_basis', 32)->default('net_realized_sales');
                $table->dateTime('effective_from');
                $table->dateTime('effective_to')->nullable();
                $table->string('reason', 255);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('idempotency_key', 120)->nullable();
                $table->timestamps();

                $table->unique(['scope_key', 'effective_from'], 'bssr_scope_from_uq');
                $table->index(['branch_id', 'effective_from', 'effective_to'], 'bssr_branch_window_idx');
                $table->index(['scope_key', 'effective_from', 'effective_to'], 'bssr_scope_window_idx');
            });
        }

        if (!Schema::hasTable('invoice_item_branch_shares')) {
            Schema::create('invoice_item_branch_shares', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
                $table->foreignId('invoice_item_id')->unique()->constrained('invoice_items')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('currency', 16);
                $table->unsignedInteger('quantity');
                $table->decimal('net_sales_amount', 15, 2);
                $table->unsignedInteger('rate_bps');
                $table->decimal('share_amount', 15, 2);
                $table->foreignId('rule_id')->nullable()->constrained('branch_sales_share_rules')->nullOnDelete();
                $table->string('calculation_basis', 32)->default('net_realized_sales');
                $table->timestamps();

                $table->index(['branch_id', 'currency', 'created_at'], 'iibs_branch_currency_idx');
                $table->index('invoice_id');
            });
        }

        if (!Schema::hasTable('branch_share_return_allocations')) {
            Schema::create('branch_share_return_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_return_id')->constrained('customer_returns')->restrictOnDelete();
                $table->unsignedBigInteger('return_allocation_id');
                $table->foreignId('sale_share_id')->constrained('invoice_item_branch_shares')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('currency', 16);
                $table->unsignedInteger('returned_quantity');
                $table->decimal('reversed_base_amount', 15, 2);
                $table->decimal('reversed_share_amount', 15, 2);
                $table->timestamps();

                $table->unique('return_allocation_id', 'bsra_return_alloc_uq');
                $table->index(['sale_share_id', 'customer_return_id'], 'bsra_share_return_idx');
                $table->foreign('return_allocation_id', 'bsra_return_alloc_fk')
                    ->references('id')->on('customer_return_lot_allocations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_share_return_allocations');
        Schema::dropIfExists('invoice_item_branch_shares');
        Schema::dropIfExists('branch_sales_share_rules');
        Schema::dropIfExists('branch_sales_share_batches');
    }
};
