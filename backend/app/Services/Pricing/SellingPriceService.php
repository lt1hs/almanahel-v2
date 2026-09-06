<?php

namespace App\Services\Pricing;

use App\Exceptions\DomainException;
use App\Models\BookBranchPrice;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\SellingPriceRevision;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class SellingPriceService
{
    /**
     * @return array{price: string, version: int, revision_id: int|null, source: string}
     */
    public function current(int $bookId, int $branchId, string $currency, bool $lock = false): array
    {
        $this->assertCurrency($currency);
        $row = $this->findRow($bookId, $branchId, $lock);
        $column = $currency === 'dinar' ? 'price_dinar' : 'price_toman';
        $versionColumn = $currency === 'dinar' ? 'price_dinar_version' : 'price_toman_version';
        $revisionColumn = $currency === 'dinar' ? 'dinar_revision_id' : 'toman_revision_id';

        if ($row && $row->{$column} !== null && $row->{$column} !== '') {
            return [
                'price' => Money::of($row->{$column}),
                'version' => (int) ($row->{$versionColumn} ?: 1),
                'revision_id' => $row->{$revisionColumn} ? (int) $row->{$revisionColumn} : null,
                'source' => 'book_branch_prices',
            ];
        }

        $inventory = $this->activeInventory($bookId, $branchId, $lock);
        $fallback = $inventory?->{$column};

        return [
            'price' => Money::of($fallback ?? 0),
            'version' => $row ? (int) ($row->{$versionColumn} ?: 1) : 1,
            'revision_id' => $row && $row->{$revisionColumn} ? (int) $row->{$revisionColumn} : null,
            'source' => 'inventory',
        ];
    }

    /**
     * @return array{price: string, version: int, revision_id: int|null, source: string}
     */
    public function lockCurrent(int $bookId, int $branchId, string $currency): array
    {
        return $this->current($bookId, $branchId, $currency, true);
    }

    public function setPrice(
        int $bookId,
        int $branchId,
        string $currency,
        mixed $newPrice,
        string $reason,
        ?User $actor = null,
        ?int $batchId = null,
        bool $intake = false,
        bool $allowNoOp = true,
    ): ?SellingPriceRevision {
        $this->assertCurrency($currency);
        $actor ??= Auth::user();
        if ($actor) {
            BranchAccess::assertCanChangeSellingPrice($actor, $branchId, $intake);
        }

        $branch = Branch::query()->lockForUpdate()->find($branchId);
        if (!$branch) {
            throw new DomainException('شعبه یافت نشد', 422, ['branch_id' => $branchId]);
        }
        $this->assertBranchAcceptsCurrency($branch, $currency);
        if (!$intake && !(bool) $branch->can_set_pricing) {
            throw new DomainException('این شعبه اجازه قیمت‌گذاری ندارد', 422, [
                'branch_id' => $branchId,
                'reason' => 'can_set_pricing_false',
            ]);
        }

        $amount = Money::of($newPrice);
        if (Money::isZero($amount) || Money::isNegative($amount)) {
            throw new DomainException('قیمت فروش باید بزرگ‌تر از صفر باشد', 422, [
                'book_id' => $bookId,
                'currency' => $currency,
            ]);
        }

        $row = $this->lockOrCreateRow($bookId, $branchId);
        $priceColumn = $currency === 'dinar' ? 'price_dinar' : 'price_toman';
        $versionColumn = $currency === 'dinar' ? 'price_dinar_version' : 'price_toman_version';
        $revisionColumn = $currency === 'dinar' ? 'dinar_revision_id' : 'toman_revision_id';

        $old = $row->{$priceColumn};
        if ($allowNoOp && $old !== null && $old !== '' && Money::cmp($old, $amount) === 0) {
            $this->mirrorInventory($bookId, $branchId, $currency, $amount);
            return $row->{$revisionColumn}
                ? SellingPriceRevision::query()->find($row->{$revisionColumn})
                : null;
        }

        $previousId = $row->{$revisionColumn} ? (int) $row->{$revisionColumn} : null;
        $nextVersion = max(1, (int) ($row->{$versionColumn} ?: 1));
        if ($previousId || ($old !== null && $old !== '')) {
            $nextVersion++;
        }

        $revision = SellingPriceRevision::create([
            'price_change_batch_id' => $batchId,
            'book_id' => $bookId,
            'branch_id' => $branchId,
            'currency' => $currency,
            'old_price' => $old !== null && $old !== '' ? Money::of($old) : null,
            'new_price' => $amount,
            'version' => $nextVersion,
            'previous_revision_id' => $previousId,
            'effective_at' => now(),
            'created_by' => $actor?->id,
            'reason' => $reason,
        ]);

        $row->{$priceColumn} = $amount;
        $row->{$versionColumn} = $nextVersion;
        $row->{$revisionColumn} = $revision->id;
        $row->save();

        $this->mirrorInventory($bookId, $branchId, $currency, $amount);

        ActivityLogger::record(
            'pricing',
            'selling_price_revised',
            "قیمت فروش کتاب #{$bookId} شعبه #{$branchId} ({$currency}) {$amount}",
            $revision,
            [
                'book_id' => $bookId,
                'branch_id' => $branchId,
                'currency' => $currency,
                'old_price' => $revision->old_price,
                'new_price' => $revision->new_price,
                'version' => $nextVersion,
                'reason' => $reason,
                'batch_id' => $batchId,
            ],
            $branchId,
            $actor?->id,
        );

        return $revision;
    }

    /**
     * @param  array<string, mixed>  $prices
     */
    public function applyInventoryPrices(
        Inventory $inventory,
        array $prices,
        string $reason,
        ?User $actor = null,
        bool $intake = false,
    ): Inventory {
        foreach (['toman' => 'price_toman', 'dinar' => 'price_dinar'] as $currency => $key) {
            if (!array_key_exists($key, $prices) || $prices[$key] === null || $prices[$key] === '') {
                continue;
            }
            if (Money::isZero($prices[$key]) || Money::isNegative($prices[$key])) {
                continue;
            }
            $this->setPrice(
                (int) $inventory->book_id,
                (int) $inventory->branch_id,
                $currency,
                $prices[$key],
                $reason,
                $actor,
                null,
                $intake,
            );
        }

        return $inventory->fresh();
    }

    /**
     * @param  Collection<int, Inventory>|iterable<Inventory>  $rows
     */
    public function decorateInventories(iterable $rows): void
    {
        $list = collect($rows);
        if ($list->isEmpty()) {
            return;
        }
        $bookIds = $list->pluck('book_id')->unique()->values();
        $branchIds = $list->pluck('branch_id')->unique()->values();
        $prices = BookBranchPrice::query()
            ->whereIn('book_id', $bookIds)
            ->whereIn('branch_id', $branchIds)
            ->get()
            ->keyBy(fn (BookBranchPrice $row) => $row->book_id.':'.$row->branch_id);

        foreach ($list as $inv) {
            $row = $prices->get($inv->book_id.':'.$inv->branch_id);
            $tomanVersion = $row ? (int) ($row->price_toman_version ?: 1) : 1;
            $dinarVersion = $row ? (int) ($row->price_dinar_version ?: 1) : 1;
            if ($row && $row->price_toman !== null && $row->price_toman !== '') {
                $inv->setAttribute('price_toman', $row->price_toman);
            }
            if ($row && $row->price_dinar !== null && $row->price_dinar !== '') {
                $inv->setAttribute('price_dinar', $row->price_dinar);
            }
            $inv->setAttribute('price_toman_version', $tomanVersion);
            $inv->setAttribute('price_dinar_version', $dinarVersion);
            $branch = $inv->relationLoaded('branch') ? $inv->branch : null;
            $preferDinar = $branch && (bool) $branch->supports_dinar && !(bool) $branch->supports_toman;
            $inv->setAttribute('price_version', $preferDinar ? $dinarVersion : $tomanVersion);
        }
    }

    public function history(int $bookId, ?int $branchId = null, ?string $currency = null)
    {
        $q = SellingPriceRevision::query()
            ->where('book_id', $bookId)
            ->with(['branch:id,name', 'creator:id,name'])
            ->orderByDesc('id');
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        if ($currency) {
            $q->where('currency', $currency);
        }

        return $q->limit(200)->get();
    }

    private function findRow(int $bookId, int $branchId, bool $lock): ?BookBranchPrice
    {
        $q = BookBranchPrice::query()->where('book_id', $bookId)->where('branch_id', $branchId);
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }

    private function lockOrCreateRow(int $bookId, int $branchId): BookBranchPrice
    {
        $row = $this->findRow($bookId, $branchId, true);
        if ($row) {
            return $row;
        }

        return BookBranchPrice::create([
            'book_id' => $bookId,
            'branch_id' => $branchId,
            'price_toman_version' => 1,
            'price_dinar_version' => 1,
        ]);
    }

    private function activeInventory(int $bookId, int $branchId, bool $lock): ?Inventory
    {
        $q = Inventory::query()
            ->where('book_id', $bookId)
            ->where('branch_id', $branchId)
            ->whereNull('superseded_by_inventory_id')
            ->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }

    private function mirrorInventory(int $bookId, int $branchId, string $currency, string $amount): void
    {
        $inventory = $this->activeInventory($bookId, $branchId, true);
        if (!$inventory) {
            $inventory = Inventory::create([
                'book_id' => $bookId,
                'branch_id' => $branchId,
                'quantity' => 0,
                'type' => 'owned',
            ]);
        }
        $column = $currency === 'dinar' ? 'price_dinar' : 'price_toman';
        $inventory->{$column} = $amount;
        $inventory->save();
    }

    public function assertBranchAcceptsCurrency(Branch $branch, string $currency): void
    {
        $ok = $currency === 'dinar'
            ? (bool) $branch->supports_dinar
            : (bool) $branch->supports_toman;
        if (!$ok) {
            throw new DomainException('این شعبه این ارز را پشتیبانی نمی‌کند', 422, [
                'branch_id' => $branch->id,
                'currency' => $currency,
                'reason' => 'currency_unsupported',
            ]);
        }
    }

    private function assertCurrency(string $currency): void
    {
        if (!in_array($currency, ['toman', 'dinar'], true)) {
            throw new DomainException('ارز نامعتبر است', 422);
        }
    }
}
