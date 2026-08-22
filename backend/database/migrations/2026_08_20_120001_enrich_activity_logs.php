<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'event_uuid')) {
                $table->uuid('event_uuid')->nullable()->unique();
            }
            if (! Schema::hasColumn('activity_logs', 'request_id')) {
                $table->uuid('request_id')->nullable()->index();
            }
            if (! Schema::hasColumn('activity_logs', 'severity')) {
                $table->string('severity', 16)->default('info')->index();
            }
            if (! Schema::hasColumn('activity_logs', 'http_method')) {
                $table->string('http_method', 10)->nullable();
            }
            if (! Schema::hasColumn('activity_logs', 'route')) {
                $table->string('route', 255)->nullable();
            }
            if (! Schema::hasColumn('activity_logs', 'status_code')) {
                $table->unsignedSmallInteger('status_code')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (Schema::hasColumn('activity_logs', 'event_uuid')) {
                $table->dropUnique(['event_uuid']);
            }
            if (Schema::hasColumn('activity_logs', 'request_id')) {
                $table->dropIndex(['request_id']);
            }
            if (Schema::hasColumn('activity_logs', 'severity')) {
                $table->dropIndex(['severity']);
            }
            $drops = array_values(array_filter(
                ['event_uuid', 'request_id', 'severity', 'http_method', 'route', 'status_code'],
                fn (string $column) => Schema::hasColumn('activity_logs', $column)
            ));
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
