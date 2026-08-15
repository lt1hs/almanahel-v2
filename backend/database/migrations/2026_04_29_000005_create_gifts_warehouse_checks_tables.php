<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('book_id')->constrained()->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->string('recipient_name');
            $table->string('recipient_phone')->nullable();
            $table->text('reason')->nullable();
            $table->decimal('cost_value', 15, 2); // the value that must be paid to supplier if consignment
            $table->enum('currency', ['toman', 'dinar'])->default('toman');
            $table->boolean('is_consignment')->default(false); // if true, cost must be paid to supplier
            $table->foreignId('supplier_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('accounting_status', ['pending', 'settled'])->default('pending');
            $table->date('gifted_at');
            $table->timestamps();
        });

        Schema::create('warehouse_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->onDelete('cascade'); // the warehouse branch
            $table->foreignId('book_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('direction', ['in', 'out'])->default('in');
            $table->integer('quantity');
            $table->string('handler_name'); // who delivered or received
            $table->string('handler_phone')->nullable();
            $table->enum('reason', ['received_from_supplier', 'transferred_to_branch', 'returned_from_branch', 'adjustment', 'other'])->default('received_from_supplier');
            $table->foreignId('related_transfer_id')->nullable()->constrained('transfers')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->date('log_date');
            $table->timestamps();
        });

        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->string('check_number');
            $table->string('bank_name')->nullable();
            $table->string('payer_name');
            $table->string('payer_phone')->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('currency', ['toman', 'dinar'])->default('toman');
            $table->date('due_date');
            $table->enum('status', ['pending', 'cleared', 'bounced'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checks');
        Schema::dropIfExists('warehouse_logs');
        Schema::dropIfExists('gifts');
    }
};
