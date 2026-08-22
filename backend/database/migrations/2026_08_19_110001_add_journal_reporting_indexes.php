<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('journal_entries'))->pluck('name');
                if (Schema::hasColumn('journal_entries', 'occurred_at') && !$indexes->contains('je_occurred_at_idx')) {
                    $table->index('occurred_at', 'je_occurred_at_idx');
                }
                if (Schema::hasColumn('journal_entries', 'event_type')
                    && !$indexes->contains('je_source_event_ver_idx')) {
                    $table->index(
                        ['source_type', 'source_id', 'event_type', 'version'],
                        'je_source_event_ver_idx'
                    );
                }
            });
        }

        if (!Schema::hasTable('journal_lines')) {
            return;
        }

        Schema::table('journal_lines', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('journal_lines'))->pluck('name');
            if (Schema::hasColumn('journal_lines', 'branch_id') && !$indexes->contains('jl_branch_currency_idx')) {
                $table->index(['branch_id', 'currency'], 'jl_branch_currency_idx');
            }
            if (!$indexes->contains('jl_ledger_currency_idx')) {
                $table->index(['ledger_account_id', 'currency'], 'jl_ledger_currency_idx');
            }
            if (Schema::hasColumn('journal_lines', 'financial_account_id')
                && !$indexes->contains('jl_financial_account_idx')) {
                $table->index('financial_account_id', 'jl_financial_account_idx');
            }
            if (Schema::hasColumn('journal_lines', 'supplier_id')
                && !$indexes->contains('jl_party_origin_idx')) {
                $table->index(['supplier_id', 'customer_id', 'origin_scope'], 'jl_party_origin_idx');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('journal_entries'))->pluck('name');
                foreach (['je_occurred_at_idx', 'je_source_event_ver_idx'] as $name) {
                    if ($indexes->contains($name)) {
                        $table->dropIndex($name);
                    }
                }
            });
        }
        if (Schema::hasTable('journal_lines')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('journal_lines'))->pluck('name');
                foreach ([
                    'jl_branch_currency_idx',
                    'jl_ledger_currency_idx',
                    'jl_financial_account_idx',
                    'jl_party_origin_idx',
                ] as $name) {
                    if ($indexes->contains($name)) {
                        $table->dropIndex($name);
                    }
                }
            });
        }
    }
};
