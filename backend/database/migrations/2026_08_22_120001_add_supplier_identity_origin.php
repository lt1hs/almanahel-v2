<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('suppliers') && !Schema::hasColumn('suppliers', 'identity_origin')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->string('identity_origin', 32)->default('admin')->after('status');
                $table->foreignId('origin_branch_id')->nullable()->after('identity_origin')
                    ->constrained('branches')->nullOnDelete();
                $table->index('identity_origin');
            });
        }

        if (Schema::hasTable('supplier_accounts') && !Schema::hasColumn('supplier_accounts', 'created_canonical_supplier')) {
            Schema::table('supplier_accounts', function (Blueprint $table) {
                $table->boolean('created_canonical_supplier')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('supplier_accounts') && Schema::hasColumn('supplier_accounts', 'created_canonical_supplier')) {
            Schema::table('supplier_accounts', function (Blueprint $table) {
                $table->dropColumn('created_canonical_supplier');
            });
        }
        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'identity_origin')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('origin_branch_id');
                $table->dropColumn('identity_origin');
            });
        }
    }
};
