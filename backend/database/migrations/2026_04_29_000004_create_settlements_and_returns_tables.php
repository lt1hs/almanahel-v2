<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('settlement_number')->unique();
            $table->enum('period_type', ['monthly', 'quarterly', 'custom'])->default('monthly');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 15, 2);
            $table->enum('currency', ['toman', 'dinar'])->default('toman');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'check'])->default('cash');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('return_number')->unique();
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->enum('refund_method', ['cash', 'credit'])->default('cash');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_id')->constrained()->onDelete('cascade');
            $table->foreignId('book_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_item_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->timestamps();
        });

        Schema::create('consignment_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('return_number')->unique();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('consignment_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_return_id')->constrained()->onDelete('cascade');
            $table->foreignId('book_id')->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('cost_price', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_return_items');
        Schema::dropIfExists('consignment_returns');
        Schema::dropIfExists('customer_return_items');
        Schema::dropIfExists('customer_returns');
        Schema::dropIfExists('settlements');
    }
};
