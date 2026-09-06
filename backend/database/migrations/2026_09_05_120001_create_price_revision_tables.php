<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('price_change_batches')) {
            Schema::create('price_change_batches', function (Blueprint $table) {
                $table->id();
                $table->string('type', 32);
                $table->string('scope', 32);
                $table->string('status', 32)->default('draft');
                $table->string('reason', 255);
                $table->timestamp('effective_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('idempotency_key', 80)->unique();
                $table->string('request_hash', 64);
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['type', 'status']);
            });
        }

        if (!Schema::hasTable('selling_price_revisions')) {
            Schema::create('selling_price_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_change_batch_id')->nullable()->constrained('price_change_batches')->nullOnDelete();
                $table->foreignId('book_id')->constrained()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->string('currency', 16);
                $table->decimal('old_price', 15, 2)->nullable();
                $table->decimal('new_price', 15, 2);
                $table->unsignedInteger('version');
                $table->foreignId('previous_revision_id')->nullable()->constrained('selling_price_revisions')->nullOnDelete();
                $table->timestamp('effective_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason', 255)->nullable();
                $table->timestamps();

                $table->unique(['book_id', 'branch_id', 'currency', 'version'], 'spr_book_branch_currency_version_uq');
                $table->index(['book_id', 'branch_id', 'currency', 'effective_at'], 'spr_book_branch_currency_effective_idx');
            });
        }

        if (!Schema::hasTable('consignment_cost_revisions')) {
            Schema::create('consignment_cost_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_change_batch_id')->nullable()->constrained('price_change_batches')->nullOnDelete();
                $table->foreignId('book_id')->constrained()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('supplier_account_id')->nullable()->constrained('supplier_accounts')->nullOnDelete();
                $table->string('currency', 16);
                $table->decimal('new_cost', 15, 2);
                $table->timestamp('effective_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason', 255)->nullable();
                $table->timestamps();

                $table->index(['book_id', 'branch_id', 'currency'], 'ccr_book_branch_currency_idx');
            });
        }

        if (!Schema::hasTable('consignment_cost_revision_lots')) {
            Schema::create('consignment_cost_revision_lots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('consignment_cost_revision_id');
                $table->foreign('consignment_cost_revision_id', 'ccrl_revision_fk')
                    ->references('id')->on('consignment_cost_revisions')->cascadeOnDelete();
                $table->foreignId('stock_lot_id')->constrained('stock_lots')->restrictOnDelete();
                $table->decimal('old_payable_unit_cost', 15, 2)->nullable();
                $table->decimal('new_payable_unit_cost', 15, 2);
                $table->unsignedInteger('quantity_available_at_change')->default(0);
                $table->timestamps();

                $table->index('stock_lot_id');
            });
        }

        $this->addUnsignedIntegerColumn('book_branch_prices', 'price_toman_version', 1);
        $this->addUnsignedIntegerColumn('book_branch_prices', 'price_dinar_version', 1);
        $this->addNullableFk('book_branch_prices', 'toman_revision_id', 'selling_price_revisions');
        $this->addNullableFk('book_branch_prices', 'dinar_revision_id', 'selling_price_revisions');

        $this->addNullableFk('invoice_items', 'selling_price_revision_id', 'selling_price_revisions');
        $this->addUnsignedIntegerColumn('invoice_items', 'selling_price_version', null);

        $this->addDecimalColumn('stock_lots', 'payable_unit_cost');
        $this->addNullableFk('stock_lots', 'current_cost_revision_id', 'consignment_cost_revisions');

        foreach (['sale_lot_allocations', 'gift_lot_allocations'] as $tableName) {
            $this->addNullableFk($tableName, 'consignment_cost_revision_id', 'consignment_cost_revisions');
        }
    }

    public function down(): void
    {
        foreach (['sale_lot_allocations', 'gift_lot_allocations'] as $tableName) {
            $this->dropColumns($tableName, ['consignment_cost_revision_id']);
        }
        $this->dropColumns('stock_lots', ['current_cost_revision_id', 'payable_unit_cost']);
        $this->dropColumns('invoice_items', ['selling_price_revision_id', 'selling_price_version']);
        $this->dropColumns('book_branch_prices', [
            'toman_revision_id',
            'dinar_revision_id',
            'price_toman_version',
            'price_dinar_version',
        ]);

        Schema::dropIfExists('consignment_cost_revision_lots');
        Schema::dropIfExists('consignment_cost_revisions');
        Schema::dropIfExists('selling_price_revisions');
        Schema::dropIfExists('price_change_batches');
    }

    private function addUnsignedIntegerColumn(string $table, string $column, ?int $default): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($column, $default) {
            $col = $blueprint->unsignedInteger($column);
            if ($default === null) {
                $col->nullable();
            } else {
                $col->default($default);
            }
        });
    }

    private function addDecimalColumn(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->decimal($column, 15, 2)->nullable();
        });
    }

    private function addNullableFk(string $table, string $column, string $references): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($column, $references) {
            $blueprint->foreignId($column)->nullable()->constrained($references)->nullOnDelete();
        });
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
