<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'sold_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->timestamp('sold_at')->nullable();
            });
            DB::table('invoices')->whereNull('sold_at')->update(['sold_at' => DB::raw('created_at')]);
        }

        if (Schema::hasTable('customer_returns') && !Schema::hasColumn('customer_returns', 'returned_at')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->timestamp('returned_at')->nullable();
            });
            DB::table('customer_returns')->whereNull('returned_at')->update(['returned_at' => DB::raw('created_at')]);
        }

        if (Schema::hasTable('customer_returns')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_returns', 'receivable_reduction')) {
                    $table->decimal('receivable_reduction', 18, 2)->nullable();
                }
                if (!Schema::hasColumn('customer_returns', 'cash_refund')) {
                    $table->decimal('cash_refund', 18, 2)->nullable();
                }
                if (!Schema::hasColumn('customer_returns', 'customer_credit_created')) {
                    $table->decimal('customer_credit_created', 18, 2)->nullable();
                }
                if (!Schema::hasColumn('customer_returns', 'currency')) {
                    $table->string('currency', 16)->nullable();
                }
            });
        }

        if (Schema::hasTable('settlements') && !Schema::hasColumn('settlements', 'paid_at')) {
            Schema::table('settlements', function (Blueprint $table) {
                $table->timestamp('paid_at')->nullable();
            });
            DB::table('settlements')->whereNull('paid_at')->update(['paid_at' => DB::raw('created_at')]);
        }

        if (Schema::hasTable('checks')) {
            Schema::table('checks', function (Blueprint $table) {
                if (!Schema::hasColumn('checks', 'cleared_at')) {
                    $table->timestamp('cleared_at')->nullable();
                }
                if (!Schema::hasColumn('checks', 'bounced_at')) {
                    $table->timestamp('bounced_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('warehouse_logs') && !Schema::hasColumn('warehouse_logs', 'stock_lot_id')) {
            Schema::table('warehouse_logs', function (Blueprint $table) {
                $table->foreignId('stock_lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('customer_payments') && !Schema::hasColumn('customer_payments', 'financial_account_id')) {
            Schema::table('customer_payments', function (Blueprint $table) {
                $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('settlements')) {
            Schema::table('settlements', function (Blueprint $table) {
                if (!Schema::hasColumn('settlements', 'cleared_at')) {
                    $table->timestamp('cleared_at')->nullable();
                }
                if (!Schema::hasColumn('settlements', 'bounced_at')) {
                    $table->timestamp('bounced_at')->nullable();
                }
                if (!Schema::hasColumn('settlements', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('customer_payments')) {
            Schema::table('customer_payments', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_payments', 'idempotency_key')) {
                    $table->string('idempotency_key', 80)->nullable();
                }
                if (!Schema::hasColumn('customer_payments', 'payload_hash')) {
                    $table->string('payload_hash', 64)->nullable();
                }
            });
            $indexes = collect(Schema::getIndexes('customer_payments'))->pluck('name');
            if (!$indexes->contains('customer_payments_idempotency_key_unique')
                && Schema::hasColumn('customer_payments', 'idempotency_key')) {
                Schema::table('customer_payments', function (Blueprint $table) {
                    $table->unique('idempotency_key', 'customer_payments_idempotency_key_unique');
                });
            }
        }

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'payment_status')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE invoices MODIFY payment_status ENUM('paid','pending','overdue','partially_paid') NOT NULL DEFAULT 'paid'");
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'invoices' => ['sold_at'],
            'customer_returns' => ['returned_at', 'receivable_reduction', 'cash_refund', 'customer_credit_created', 'currency'],
            'settlements' => ['paid_at', 'cleared_at', 'bounced_at', 'cancelled_at'],
            'checks' => ['cleared_at', 'bounced_at'],
            'warehouse_logs' => ['stock_lot_id'],
            'customer_payments' => ['financial_account_id', 'idempotency_key', 'payload_hash'],
        ] as $table => $cols) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $existing = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
            if ($existing === []) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($existing) {
                $blueprint->dropColumn($existing);
            });
        }
    }
};
