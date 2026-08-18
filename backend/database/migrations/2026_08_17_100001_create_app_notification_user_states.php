<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_notification_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_notification_id')->constrained('app_notifications')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'app_notification_id'], 'notif_user_state_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notification_user_states');
    }
};
