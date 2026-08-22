<?php

namespace App\Services\Catalog;

use App\Models\BranchCatalogItem;
use App\Models\StockLot;
use App\Support\Catalog\CatalogSource;
use Illuminate\Support\Facades\Auth;

class BranchCatalogService
{
    public function ensure(
        int $branchId,
        int $bookId,
        string $source,
        ?int $localSupplierAccountId = null,
        ?int $createdBy = null,
        bool $active = true
    ): BranchCatalogItem {
        if (!CatalogSource::isValid($source)) {
            throw new \InvalidArgumentException("Invalid catalog source: {$source}");
        }

        $existing = BranchCatalogItem::query()
            ->where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->first();

        if ($existing) {
            $updates = [];
            if ($existing->source === CatalogSource::LEGACY_UNKNOWN && $source !== CatalogSource::LEGACY_UNKNOWN) {
                $updates['source'] = $source;
            }
            if ($localSupplierAccountId && !$existing->local_supplier_account_id) {
                $updates['local_supplier_account_id'] = $localSupplierAccountId;
            }
            if ($updates) {
                $existing->update($updates);
            }

            return $existing->fresh();
        }

        return BranchCatalogItem::create([
            'branch_id' => $branchId,
            'book_id' => $bookId,
            'source' => $source,
            'local_supplier_account_id' => $localSupplierAccountId,
            'active' => $active,
            'created_by' => $createdBy ?? Auth::id(),
        ]);
    }

    public function inferBackfillSource(int $branchId, int $bookId): string
    {
        $hasTransferProof = StockLot::query()
            ->where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->where(function ($query) {
                $query->whereNotNull('parent_lot_id')
                    ->orWhere('migration_source', 'transfer');
            })
            ->exists();

        if ($hasTransferProof) {
            return CatalogSource::TRANSFERRED;
        }

        return CatalogSource::LEGACY_UNKNOWN;
    }

    public function catalogBookIdsQuery(int $branchId)
    {
        return BranchCatalogItem::query()
            ->where('branch_id', $branchId)
            ->where('active', true)
            ->select('book_id');
    }
}
