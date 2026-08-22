<?php

namespace App\Support\Catalog;

use App\Models\Branch;
use App\Support\IntakePolicy;

final class CatalogSourceResolver
{
    /**
     * Determine trusted catalog source from intake workflow — never from raw client input.
     *
     * @param  array<string, mixed>  $intakeData
     */
    public static function forIntake(int $branchId, array $intakeData): string
    {
        if (($intakeData['migration_source'] ?? '') === 'transfer' || !empty($intakeData['parent_lot_id'])) {
            return CatalogSource::TRANSFERRED;
        }

        $branch = Branch::find($branchId);
        if ($branch && (IntakePolicy::isIntakeHub($branch) || $branch->is_central_warehouse)) {
            return CatalogSource::CENTRAL;
        }

        return CatalogSource::LOCAL;
    }

    /** Pricing-only catalog link — local branch workflow, no stock movement. */
    public static function forPricing(int $branchId): string
    {
        $branch = Branch::find($branchId);
        if ($branch && (IntakePolicy::isIntakeHub($branch) || $branch->is_central_warehouse)) {
            return CatalogSource::CENTRAL;
        }

        return CatalogSource::LOCAL;
    }
}
