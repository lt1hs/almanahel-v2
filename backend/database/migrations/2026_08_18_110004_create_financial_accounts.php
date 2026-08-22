<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** @return list<array{0: string, 1: string, 2: string}> */
    private function systemAccounts(): array
    {
        return [
            ['owned_inventory', 'موجودی ملکی', 'asset'],
            ['sales_revenue', 'درآمد فروش', 'revenue'],
            ['sales_returns', 'برگشت از فروش', 'revenue'],
            ['cogs', 'بهای تمام‌شده', 'expense'],
            ['operating_expense', 'هزینه عملیاتی', 'expense'],
            ['gift_expense', 'هزینه هدایا', 'expense'],
            ['customer_credit_liability', 'بستانکاری مشتری', 'liability'],
            ['supplier_recoverable', 'طلب از تأمین‌کننده', 'asset'],
            ['trade_payable', 'حساب‌های پرداختنی خرید', 'liability'],
            ['cash_drawer', 'صندوق', 'asset'],
            ['bank', 'بانک', 'asset'],
            ['card_clearing', 'درگاه کارت', 'asset'],
            ['checks_receivable', 'چک‌های دریافتنی', 'asset'],
            ['accounts_receivable', 'حساب‌های دریافتنی', 'asset'],
            ['supplier_payable', 'بدهی تأمین‌کننده', 'liability'],
            ['checks_payable', 'چک‌های پرداختنی', 'liability'],
        ];
    }

    /** @return list<string> */
    private function systemCodes(): array
    {
        $codes = [];
        foreach (['toman', 'dinar'] as $currency) {
            foreach ($this->systemAccounts() as [$code]) {
                $codes[] = 'sys.' . $code . '.' . $currency;
            }
        }

        return $codes;
    }

    public function up(): void
    {
        if (!Schema::hasTable('financial_accounts')) {
            Schema::create('financial_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
                $table->enum('currency', ['toman', 'dinar']);
                $table->string('type', 40);
                $table->string('name');
                $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('default_scope_key', 64)->nullable();
                $table->string('bank_name')->nullable();
                $table->string('account_number')->nullable();
                $table->string('iban')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique('default_scope_key', 'financial_accounts_default_scope_unique');
                $table->index(['branch_id', 'currency', 'type', 'is_default', 'is_active'], 'fin_acct_default_lookup');
            });
        }

        if (Schema::hasTable('journal_lines') && !Schema::hasColumn('journal_lines', 'financial_account_id')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            });
        }

        $now = now();
        foreach (['toman', 'dinar'] as $currency) {
            foreach ($this->systemAccounts() as [$code, $name, $type]) {
                $full = 'sys.' . $code . '.' . $currency;
                if (DB::table('ledger_accounts')->where('code', $full)->exists()) {
                    continue;
                }
                DB::table('ledger_accounts')->insert([
                    'code' => $full,
                    'name' => $name,
                    'type' => $type,
                    'branch_id' => null,
                    'currency' => $currency,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('journal_lines') && Schema::hasColumn('journal_lines', 'financial_account_id')) {
            $lineRefs = (int) DB::table('journal_lines')->whereNotNull('financial_account_id')->count();
            if ($lineRefs > 0) {
                throw new \RuntimeException(
                    'Cannot drop financial_accounts: journal_lines.financial_account_id is in use. Leave chart accounts in place.'
                );
            }
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->dropConstrainedForeignId('financial_account_id');
            });
        }

        if (Schema::hasTable('financial_accounts')) {
            $ids = DB::table('ledger_accounts')->whereIn('code', $this->systemCodes())->pluck('id');
            $acctRefs = (int) DB::table('financial_accounts')->whereIn('ledger_account_id', $ids)->count();
            if ($acctRefs > 0) {
                throw new \RuntimeException(
                    'Cannot drop financial_accounts: treasury rows still reference system ledger accounts.'
                );
            }
            Schema::dropIfExists('financial_accounts');
        }
    }
};
