<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->integer('low_stock_threshold')->nullable()->after('iraq_only');
            $table->string('description')->nullable()->after('low_stock_threshold');
            $table->string('language')->default('ar')->after('description');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->decimal('cost_price_toman', 15, 2)->nullable()->after('price_dinar');
            $table->decimal('cost_price_dinar', 15, 2)->nullable()->after('cost_price_toman');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['low_stock_threshold', 'description', 'language']);
        });
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn(['cost_price_toman', 'cost_price_dinar']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('iraq_only_visible_branches');
        });
    }
};
