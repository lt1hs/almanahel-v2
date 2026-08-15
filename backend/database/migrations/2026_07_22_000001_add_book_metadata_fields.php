<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('size')->nullable()->after('publisher');
            $table->string('cover')->nullable()->after('size');
            $table->string('publication_year', 20)->nullable()->after('cover');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['size', 'cover', 'publication_year']);
        });
    }
};
