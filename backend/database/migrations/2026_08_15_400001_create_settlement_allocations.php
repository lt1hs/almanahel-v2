<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consignment_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->enum('currency', ['toman', 'dinar']);
            $table->timestamps();
        });

        Schema::table('settlements', function (Blueprint $table) {
            if (!Schema::hasColumn('settlements', 'check_number')) {
                $table->string('check_number')->nullable()->after('payment_method');
                $table->string('bank_name')->nullable()->after('check_number');
                $table->string('check_status')->nullable()->after('bank_name');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_allocations');
        Schema::table('settlements', function (Blueprint $table) {
            if (Schema::hasColumn('settlements', 'check_number')) {
                $table->dropColumn(['check_number', 'bank_name', 'check_status']);
            }
        });
    }
};
