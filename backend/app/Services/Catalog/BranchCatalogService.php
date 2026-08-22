<?php

namespace App\Services\Catalog;

use App\Models\BranchCatalogItem;
use App\Models\StockLot;
use App\Models\SupplierAccount;
use App\Exceptions\DomainException;
use App\Support\Catalog\CatalogSource;
use App\Support\Catalog\CatalogSourceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BranchCatalogService
{
    public function ensureForIntake(
        int $branchId,
        int $bookId,
        array $intakeData,
        ?int $localSupplierAccountId = null,
        ?int $createdBy = null
    ): BranchCatalogItem {
        return $this->ensure(
            $branchId,
            $bookId,
            CatalogSourceResolver::forIntake($branchId, $intakeData),
            $localSupplierAccountId,
            $createdBy,
            true,
            true
        );
    }

    public function ensureForPricing(
        int $branchId,
        int $bookId,
        ?int $localSupplierAccountId = null,
        ?int $createdBy = null
    ): BranchCatalogItem {
        return $this->ensure(
            $branchId,
            $bookId,
            CatalogSourceResolver::forPricing($branchId),
            $localSupplierAccountId,
            $createdBy,
            true,
            true
        );
    }

    public function ensure(
        int $branchId,
        int $bookId,
        string $source,
        ?int $localSupplierAccountId = null,
        ?int $createdBy = null,
        bool $active = true,
        bool $reactivate = false
    ): BranchCatalogItem {
        if (!CatalogSource::isValid($source)) {
            throw new \InvalidArgumentException("Invalid catalog source: {$source}");
        }

        if ($localSupplierAccountId !== null) {
            $this->assertSupplierAccountInBranch($localSupplierAccountId, $branchId);
        }

        return DB::transaction(function () use (
            $branchId,
            $bookId,
            $source,
            $localSupplierAccountId,
            $createdBy,
            $active,
            $reactivate
        ) {
            $existing = BranchCatalogItem::query()
                ->where('branch_id', $branchId)
                ->where('book_id', $bookId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->updateExisting($existing, $source, $localSupplierAccountId, $active, $reactivate);
            }

            try {
                return BranchCatalogItem::create([
                    'branch_id' => $branchId,
                    'book_id' => $bookId,
                    'source' => $source,
                    'local_supplier_account_id' => $localSupplierAccountId,
                    'active' => $active,
                    'created_by' => $createdBy ?? Auth::id(),
                ]);
            } catch (QueryException $e) {
                if (!$this->isDuplicateBranchBook($e)) {
                    throw $e;
                }

                $existing = BranchCatalogItem::query()
                    ->where('branch_id', $branchId)
                    ->where('book_id', $bookId)
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->updateExisting($existing, $source, $localSupplierAccountId, $active, $reactivate);
            }
        });
    }

    private function updateExisting(
        BranchCatalogItem $existing,
        string $source,
        ?int $localSupplierAccountId,
        bool $active,
        bool $reactivate
    ): BranchCatalogItem {
        $updates = [];

        if ($existing->source === CatalogSource::LEGACY_UNKNOWN && $source !== CatalogSource::LEGACY_UNKNOWN) {
            $updates['source'] = $source;
        } elseif ($existing->source === CatalogSource::LOCAL && $source === CatalogSource::CENTRAL) {
            $updates['source'] = $source;
        }

        if ($localSupplierAccountId && !$existing->local_supplier_account_id) {
            $updates['local_supplier_account_id'] = $localSupplierAccountId;
        }

        if ($reactivate && !$existing->active) {
            $updates['active'] = true;
        }

        if ($updates) {
            $existing->update($updates);
        }

        return $existing->fresh();
    }

    public function assertSupplierAccountInBranch(int $supplierAccountId, int $branchId): void
    {
        $account = SupplierAccount::query()->find($supplierAccountId);
        if (!$account || (int) $account->branch_id !== $branchId) {
            throw new DomainException('حساب تأمین‌کننده متعلق به این شعبه نیست');
        }
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

    /** @return \Illuminate\Database\Eloquent\Builder<BranchCatalogItem> */
    public function aggregateSummaryQuery()
    {
        return BranchCatalogItem::query()
            ->select('book_id')
            ->selectRaw('COUNT(DISTINCT branch_id) as active_branch_count')
            ->where('active', true)
            ->groupBy('book_id');
    }

    private function isDuplicateBranchBook(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate')
            && str_contains($message, 'branch_catalog');
    }
}
