<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('cover_image')->nullable()->after('publication_year');
            $table->decimal('weight', 10, 2)->nullable()->after('cover_image');
            $table->decimal('weight_with_packaging', 10, 2)->nullable()->after('weight');
            $table->unsignedSmallInteger('volume_count')->nullable()->after('weight_with_packaging');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['cover_image', 'weight', 'weight_with_packaging', 'volume_count']);
        });
    }
};
