<?php

use App\Support\Ledger\JournalSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('journal_entries')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('journal_entries', 'status')) {
                $table->string('status', 16)->default('active');
            }
            if (!Schema::hasColumn('journal_entries', 'version')) {
                $table->unsignedInteger('version')->default(1);
            }
            if (!Schema::hasColumn('journal_entries', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable();
            }
            if (!Schema::hasColumn('journal_entries', 'supersedes_entry_id')) {
                $table->foreignId('supersedes_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            }
        });

        DB::table('journal_entries')->whereNull('status')->update(['status' => 'active']);
        DB::table('journal_entries')->where(function ($q) {
            $q->whereNull('version')->orWhere('version', 0);
        })->update(['version' => 1]);

        $indexes = collect(Schema::getIndexes('journal_entries'))->pluck('name');
        if (!$indexes->contains('journal_source_event_version_unique')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->unique(
                    ['source_type', 'source_id', 'event_type', 'version'],
                    'journal_source_event_version_unique'
                );
            });
        }

        $this->dropIndexIfExists('journal_entries', 'journal_source_event_unique');
        $this->dropIndexIfExists('journal_entries', 'journal_entries_source_type_source_id_event_type_unique');

        $indexes = collect(Schema::getIndexes('journal_entries'))->pluck('name');
        if (Schema::hasColumn('journal_entries', 'reverses_entry_id')
            && !$indexes->contains('journal_entries_reverses_entry_id_unique')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->unique('reverses_entry_id');
            });
        }
    }

    public function down(): void
    {
        JournalSchemaGuard::assertCanRestoreSourceEventUnique();

        if (!Schema::hasTable('journal_entries')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('journal_entries'))->pluck('name');
        if (Schema::hasColumn('journal_entries', 'event_type') && !$indexes->contains('journal_source_event_unique')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->unique(['source_type', 'source_id', 'event_type'], 'journal_source_event_unique');
            });
        }

        $this->dropIndexIfExists('journal_entries', 'journal_source_event_version_unique');
        $this->dropIndexIfExists('journal_entries', 'journal_entries_reverses_entry_id_unique');

        Schema::table('journal_entries', function (Blueprint $table) {
            if (Schema::hasColumn('journal_entries', 'supersedes_entry_id')) {
                $table->dropConstrainedForeignId('supersedes_entry_id');
            }
        });

        $drop = [];
        foreach (['status', 'version', 'reversed_at'] as $col) {
            if (Schema::hasColumn('journal_entries', $col)) {
                $drop[] = $col;
            }
        }
        if ($drop !== []) {
            Schema::table('journal_entries', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        $indexes = collect(Schema::getIndexes($table))->pluck('name');
        if ($indexes->contains($name)) {
            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropUnique($name);
            });
        }
    }
};
