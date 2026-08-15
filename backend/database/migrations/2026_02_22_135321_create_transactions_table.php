<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['sale', 'return', 'adjustment'])->default('sale');
            $table->decimal('total_toman', 15, 2)->nullable();
            $table->decimal('total_dinar', 15, 2)->nullable();
            $table->enum('payment_method', ['cash', 'card', 'check', 'consignment_settlement']);
            $table->enum('payment_status', ['paid', 'pending', 'overdue'])->default('paid');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->json('metadata')->nullable(); // For discounts, over-price, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
