<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('list_price', 15, 2)->nullable()->after('unit_price');
            $table->foreignId('override_by')->nullable()->after('discount')->constrained('users')->nullOnDelete();
            $table->string('override_reason')->nullable()->after('override_by');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('override_by');
            $table->dropColumn(['list_price', 'override_reason']);
        });
    }
};
