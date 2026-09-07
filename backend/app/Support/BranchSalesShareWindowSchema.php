<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BranchSalesShareWindowSchema
{
    public const CHECK_NAME = 'bssr_valid_window';

    /**
     * MySQL TIMESTAMP with ON UPDATE CURRENT_TIMESTAMP rewrites effective_from
     * whenever effective_to is set, which breaks CHECK bssr_valid_window.
     * Convert window columns to DATETIME with a UTC session so stored instants stay put.
     */
    public static function convertTimestampColumnsToDatetime(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("SET time_zone = '+00:00'");
        self::dropValidWindowCheck();

        if (Schema::hasTable('branch_sales_share_rules')
            && (self::needsDatetime('branch_sales_share_rules', 'effective_from')
                || self::needsDatetime('branch_sales_share_rules', 'effective_to'))) {
            DB::statement(
                'ALTER TABLE branch_sales_share_rules
                 MODIFY effective_from DATETIME NOT NULL,
                 MODIFY effective_to DATETIME NULL'
            );
        }

        if (Schema::hasTable('branch_sales_share_batches')
            && self::needsDatetime('branch_sales_share_batches', 'effective_from')) {
            DB::statement(
                'ALTER TABLE branch_sales_share_batches
                 MODIFY effective_from DATETIME NOT NULL'
            );
        }
    }

    public static function ensureValidWindowCheck(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql'
            || !Schema::hasTable('branch_sales_share_rules')
            || self::checkExists()) {
            return;
        }

        DB::statement(
            'ALTER TABLE branch_sales_share_rules
             ADD CONSTRAINT '.self::CHECK_NAME.'
             CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
    }

    public static function dropValidWindowCheck(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql'
            || !Schema::hasTable('branch_sales_share_rules')
            || !self::checkExists()) {
            return;
        }

        $name = self::CHECK_NAME;

        // MariaDB + MySQL 8.0.19+: DROP CONSTRAINT
        // MySQL 8.0.16–8.0.18: DROP CHECK
        try {
            DB::statement('ALTER TABLE branch_sales_share_rules DROP CONSTRAINT '.$name);
        } catch (\Throwable) {
            try {
                DB::statement('ALTER TABLE branch_sales_share_rules DROP CHECK '.$name);
            } catch (\Throwable) {
                // Constraint already gone or engine does not support CHECK drop — continue.
            }
        }
    }

    private static function checkExists(): bool
    {
        return collect(DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            ['branch_sales_share_rules', self::CHECK_NAME, 'CHECK']
        ))->isNotEmpty();
    }

    private static function needsDatetime(string $table, string $column): bool
    {
        $meta = collect(DB::select(
            'SELECT DATA_TYPE AS data_type, EXTRA AS extra
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [$table, $column]
        ))->first();

        if ($meta === null) {
            return false;
        }

        $type = strtolower((string) $meta->data_type);
        $extra = strtolower((string) $meta->extra);

        return $type === 'timestamp' || str_contains($extra, 'on update');
    }
}
