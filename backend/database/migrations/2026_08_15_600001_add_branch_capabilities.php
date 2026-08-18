<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('is_central_warehouse')->default(false)->after('status');
            $table->boolean('is_intake_hub')->default(false)->after('is_central_warehouse');
            $table->boolean('is_iraq_store')->default(false)->after('is_intake_hub');
            $table->boolean('supports_dinar')->default(false)->after('is_iraq_store');
            $table->boolean('supports_toman')->default(true)->after('supports_dinar');
        });

        // One-time seed of capabilities from current known rows (by type/city/country).
        DB::table('branches')->where('type', 'warehouse')->update([
            'is_central_warehouse' => true,
            'is_intake_hub' => true,
            'supports_toman' => true,
        ]);
        DB::table('branches')->where('type', 'store')->where('city', 'قم')->update([
            'is_intake_hub' => true,
            'supports_toman' => true,
        ]);
        DB::table('branches')->where('type', 'store')->where('country', 'عراق')->update([
            'is_iraq_store' => true,
            'supports_dinar' => true,
            'supports_toman' => false,
        ]);
        DB::table('branches')->where('type', 'store')->where('city', 'مشهد')->update([
            'supports_toman' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'is_central_warehouse',
                'is_intake_hub',
                'is_iraq_store',
                'supports_dinar',
                'supports_toman',
            ]);
        });
    }
};
