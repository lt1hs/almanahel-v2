<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('consignment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('receipt_number')->unique();
            $table->enum('status', ['unsettled', 'partially_settled', 'settled'])->default('unsettled');
            $table->enum('currency', ['toman', 'dinar'])->default('toman');
            $table->decimal('total_value', 15, 2)->default(0); // total cost value received
            $table->decimal('settled_amount', 15, 2)->default(0);
            $table->date('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('consignment_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_receipt_id')->constrained()->onDelete('cascade');
            $table->foreignId('book_id')->constrained()->onDelete('cascade');
            $table->integer('quantity_received');
            $table->integer('quantity_sold')->default(0);
            $table->integer('quantity_returned')->default(0);
            $table->decimal('cost_price', 15, 2); // price owed to supplier per unit
            $table->decimal('selling_price', 15, 2); // price sold to customers
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_receipt_items');
        Schema::dropIfExists('consignment_receipts');
    }
};
