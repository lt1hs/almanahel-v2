<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-archive flags + block destructive cascades for new references.
 * Existing FK cascades are not rewritten in-place (MySQL limitation without recreate);
 * destroy endpoints must check history before delete.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            if (!Schema::hasColumn('books', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('updated_at');
            }
        });
        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }
        });
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            if (Schema::hasColumn('books', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
        });
        Schema::table('suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('suppliers', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
        });
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
        });
    }
};
