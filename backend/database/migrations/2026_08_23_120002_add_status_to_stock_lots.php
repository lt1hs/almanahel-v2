<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_lots') || Schema::hasColumn('stock_lots', 'status')) {
            return;
        }

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->string('status', 32)->default('available')->after('qty_reserved');
            $table->index(['branch_id', 'book_id', 'status'], 'stock_lots_branch_book_status_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('stock_lots') || !Schema::hasColumn('stock_lots', 'status')) {
            return;
        }

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropIndex('stock_lots_branch_book_status_idx');
            $table->dropColumn('status');
        });
    }
};
