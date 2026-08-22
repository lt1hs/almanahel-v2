<?php

namespace App\Support\Ledger;

use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class JournalSchemaGuard
{
    public static function assertCanRestoreSourceEventUnique(): void
    {
        if (!Schema::hasTable('journal_entries') || !Schema::hasColumn('journal_entries', 'version')) {
            return;
        }

        $maxVersion = (int) (DB::table('journal_entries')->max('version') ?? 1);
        if ($maxVersion > 1) {
            throw new RuntimeException(
                'Cannot rollback journal versioning: entries with version > 1 exist. '
                . 'Do not delete or merge journal versions. Compensate with reversal journals instead.'
            );
        }
    }
}
