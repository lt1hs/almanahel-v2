<?php

namespace App\Services\Pricing;

use App\Exceptions\DomainException;
use App\Models\Book;
use App\Models\Branch;
use App\Models\PriceChangeBatch;
use App\Models\StockLot;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Support\Money;
use App\Support\PriceFlags;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PriceChangeService
{
    public function __construct(
        private readonly SellingPriceService $selling,
        private readonly ConsignmentCostRevisionService $consignment,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload, User $user): array
    {
        $plan = $this->plan($payload, $user, false);

        return $this->presentPreview($plan);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload, User $user): array
    {
        $this->assertImmediate($payload);

        $idempotencyKey = (string) $payload['idempotency_key'];
        $requestHash = $this->requestHash($payload);

        $existing = PriceChangeBatch::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->request_hash !== $requestHash) {
                throw new DomainException('کلید تکرار با محتوای متفاوت قبلاً استفاده شده است', 409, [
                    'error' => 'idempotency_conflict',
                ]);
            }

            return $this->presentApplied($existing);
        }

        return DB::transaction(function () use ($payload, $user, $idempotencyKey, $requestHash) {
            $locked = PriceChangeBatch::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($locked) {
                if ($locked->request_hash !== $requestHash) {
                    throw new DomainException('کلید تکرار با محتوای متفاوت قبلاً استفاده شده است', 409, [
                        'error' => 'idempotency_conflict',
                    ]);
                }

                return $this->presentApplied($locked);
            }

            $plan = $this->plan($payload, $user, true);
            $incomingHash = (string) ($payload['preview_hash'] ?? '');
            if ($incomingHash === '' || !hash_equals($plan['preview_hash'], $incomingHash)) {
                throw new DomainException('پیش‌نمایش منقضی شده است', 409, [
                    'error' => 'preview_stale',
                    'preview_hash' => $plan['preview_hash'],
                ]);
            }

            $batch = PriceChangeBatch::create([
                'type' => $plan['type'],
                'scope' => $plan['scope'],
                'status' => PriceChangeBatch::STATUS_DRAFT,
                'reason' => $plan['reason'],
                'effective_at' => now(),
                'created_by' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'meta' => [
                    'book_id' => $plan['book_id'],
                    'currency' => $plan['currency'],
                    'rejected' => $plan['rejected'],
                ],
            ]);

            try {
                if ($plan['type'] === PriceChangeBatch::TYPE_SELLING) {
                    foreach ($plan['applied'] as $row) {
                        $this->selling->setPrice(
                            $plan['book_id'],
                            (int) $row['branch_id'],
                            $plan['currency'],
                            $row['new_price'],
                            $plan['reason'],
                            $user,
                            $batch->id,
                            false,
                            false,
                        );
                    }
                } else {
                    foreach ($plan['applied'] as $row) {
                        $lots = collect($row['lots'])->map(fn ($lot) => StockLot::query()->lockForUpdate()->find($lot['id']))->filter();
                        $this->consignment->applyToLots(
                            $lots,
                            $plan['book_id'],
                            (int) $row['branch_id'],
                            $row['supplier_account_id'] ?? $plan['supplier_account_id'],
                            $plan['currency'],
                            $row['new_cost'],
                            $plan['reason'],
                            $user,
                            $batch->id,
                        );
                    }
                }
                $batch->update([
                    'status' => PriceChangeBatch::STATUS_APPLIED,
                    'applied_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $batch->update(['status' => PriceChangeBatch::STATUS_FAILED]);
                throw $e;
            }

            ActivityLogger::record(
                'pricing',
                'price_change_applied',
                "اعمال تغییر قیمت {$plan['type']} کتاب #{$plan['book_id']}",
                $batch,
                [
                    'type' => $plan['type'],
                    'scope' => $plan['scope'],
                    'applied_branch_ids' => collect($plan['applied'])->pluck('branch_id')->all(),
                    'rejected' => $plan['rejected'],
                ],
                null,
                $user->id,
            );

            return $this->presentApplied($batch->fresh());
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function plan(array $payload, User $user, bool $forApply): array
    {
        $type = (string) $payload['type'];
        $scope = (string) $payload['scope'];
        $bookId = (int) $payload['book_id'];
        $currency = (string) $payload['currency'];
        $reason = trim((string) $payload['reason']);
        if ($reason === '') {
            throw new DomainException('دلیل تغییر قیمت الزامی است', 422);
        }
        $this->assertImmediate($payload);
        Book::query()->findOrFail($bookId);

        if ($type === PriceChangeBatch::TYPE_SELLING) {
            if (!PriceFlags::sellingVersioningEnabled()) {
                throw new DomainException('نسخه‌بندی قیمت فروش غیرفعال است', 403, ['error' => 'feature_disabled']);
            }
            $amount = Money::of($payload['new_price'] ?? 0);
        } elseif ($type === PriceChangeBatch::TYPE_CONSIGNMENT) {
            if (!PriceFlags::consignmentCostRevisionEnabled()) {
                throw new DomainException('تغییر بهای امانی غیرفعال است', 403, ['error' => 'feature_disabled']);
            }
            BranchAccess::assertCanChangeConsignmentCost($user);
            $amount = Money::of($payload['new_cost'] ?? 0);
        } else {
            throw new DomainException('نوع تغییر قیمت نامعتبر است', 422);
        }
        if (Money::isZero($amount) || Money::isNegative($amount)) {
            throw new DomainException('مبلغ جدید باید بزرگ‌تر از صفر باشد', 422);
        }
        $overrides = $this->overrideAmounts($payload, $type);

        [$eligibleIds, $rejected] = $this->resolveBranches($scope, $payload['branch_ids'] ?? [], $user, $currency, $type, $forApply);

        $supplierAccountId = isset($payload['supplier_account_id']) ? (int) $payload['supplier_account_id'] : null;
        $supplierId = null;
        if ($type === PriceChangeBatch::TYPE_CONSIGNMENT) {
            if (!$supplierAccountId) {
                throw new DomainException('حساب تأمین‌کننده الزامی است', 422);
            }
            $account = SupplierAccount::query()->findOrFail($supplierAccountId);
            $supplierId = $account->supplier_id ? (int) $account->supplier_id : null;
            if (!$supplierId) {
                throw new DomainException('حساب تأمین‌کننده هویت متعارف ندارد', 422);
            }
        }

        $applied = [];
        $fingerprint = [
            'type' => $type,
            'book_id' => $bookId,
            'currency' => $currency,
            'scope' => $scope,
            'new_amount' => $amount,
            'overrides' => $overrides,
            'supplier_id' => $supplierId,
            'branch_ids' => $eligibleIds,
        ];

        foreach ($eligibleIds as $branchId) {
            $rowAmount = $overrides[$branchId] ?? $amount;
            if ($type === PriceChangeBatch::TYPE_SELLING) {
                BranchAccess::assertCanChangeSellingPrice($user, $branchId, false);
                $current = $this->selling->current($bookId, $branchId, $currency, $forApply);
                $margin = $this->marginWarning($bookId, $branchId, $currency, $rowAmount, null);
                $unsold = (int) StockLot::query()
                    ->where('book_id', $bookId)
                    ->where('branch_id', $branchId)
                    ->where('currency', $currency)
                    ->sellable()
                    ->sum('qty_available');
                $reserved = (int) StockLot::query()
                    ->where('book_id', $bookId)
                    ->where('branch_id', $branchId)
                    ->where('currency', $currency)
                    ->sum('qty_reserved');
                $applied[] = [
                    'branch_id' => $branchId,
                    'old_price' => $current['price'],
                    'new_price' => $rowAmount,
                    'version' => $current['version'],
                    'unsold_qty' => $unsold,
                    'reserved_qty' => $reserved,
                    'value_delta' => Money::mul(Money::sub($rowAmount, $current['price']), $unsold),
                    'margin_warning' => $margin,
                ];
                $fingerprint['rows'][] = [
                    'branch_id' => $branchId,
                    'old_price' => $current['price'],
                    'new_price' => $rowAmount,
                    'version' => $current['version'],
                    'unsold_qty' => $unsold,
                    'reserved_qty' => $reserved,
                ];
            } else {
                $lots = $this->consignment->eligibleLots($bookId, [$branchId], $currency, $supplierId, $forApply);
                if ($forApply) {
                    $this->consignment->assertNoReserved($lots);
                }
                $branchAccount = SupplierAccount::query()
                    ->where('branch_id', $branchId)
                    ->where('supplier_id', $supplierId)
                    ->orderBy('id')
                    ->first();
                $oldCost = $lots->isNotEmpty()
                    ? $lots->first()->effectivePayableUnitCost()
                    : '0.00';
                $unsold = (int) $lots->sum('qty_available');
                $reserved = (int) $lots->sum('qty_reserved');
                $sell = $this->selling->current($bookId, $branchId, $currency, false);
                $applied[] = [
                    'branch_id' => $branchId,
                    'old_cost' => Money::of($oldCost),
                    'new_cost' => $rowAmount,
                    'lots_count' => $lots->count(),
                    'unsold_qty' => $unsold,
                    'reserved_qty' => $reserved,
                    'value_delta' => Money::mul(Money::sub($rowAmount, $oldCost), $unsold),
                    'selling_price' => $sell['price'],
                    'margin_warning' => $this->marginWarning($bookId, $branchId, $currency, null, $rowAmount),
                    'supplier_account_id' => $branchAccount?->id,
                    'lots' => $lots->map(fn (StockLot $lot) => [
                        'id' => $lot->id,
                        'old_payable_unit_cost' => $lot->effectivePayableUnitCost(),
                        'qty_available' => (int) $lot->qty_available,
                        'qty_reserved' => (int) $lot->qty_reserved,
                    ])->values()->all(),
                ];
                $fingerprint['rows'][] = [
                    'branch_id' => $branchId,
                    'lots' => $lots->map(fn (StockLot $lot) => [
                        'id' => $lot->id,
                        'old' => $lot->effectivePayableUnitCost(),
                        'qty_available' => (int) $lot->qty_available,
                        'qty_reserved' => (int) $lot->qty_reserved,
                    ])->values()->all(),
                ];
            }
        }

        $plan = [
            'type' => $type,
            'scope' => $scope,
            'book_id' => $bookId,
            'currency' => $currency,
            'reason' => $reason,
            'new_amount' => $amount,
            'supplier_account_id' => $supplierAccountId,
            'supplier_id' => $supplierId,
            'applied' => $applied,
            'rejected' => $rejected,
            'preview_hash' => hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_UNICODE)),
        ];

        return $plan;
    }

    /**
     * @param  mixed  $requestedIds
     * @return array{0: list<int>, 1: list<array{branch_id: int|null, reason: string}>}
     */
    private function resolveBranches(string $scope, mixed $requestedIds, User $user, string $currency, string $type, bool $forApply): array
    {
        $rejected = [];
        $ids = is_array($requestedIds) ? array_values(array_unique(array_map('intval', $requestedIds))) : [];

        if ($scope === PriceChangeBatch::SCOPE_SELECTED) {
            if ($ids === []) {
                throw new DomainException('انتخاب شعبه الزامی است', 422);
            }
            $applied = [];
            foreach ($ids as $id) {
                $reason = $this->rejectReason($id, $currency, $user, $type);
                if ($reason !== null) {
                    if ($forApply) {
                        throw new DomainException('شعبه منتخب نامعتبر است', 422, [
                            'branch_id' => $id,
                            'reason' => $reason,
                        ]);
                    }
                    $rejected[] = ['branch_id' => $id, 'reason' => $reason];
                    continue;
                }
                $applied[] = $id;
            }

            return [$applied, $rejected];
        }

        if ($scope !== PriceChangeBatch::SCOPE_ALL) {
            throw new DomainException('محدوده شعب نامعتبر است', 422);
        }

        $applied = [];
        $query = Branch::query()->orderBy('id');
        foreach ($query->get() as $branch) {
            $reason = $this->rejectReason((int) $branch->id, $currency, $user, $type, true);
            if ($reason !== null) {
                $rejected[] = ['branch_id' => (int) $branch->id, 'reason' => $reason];
                continue;
            }
            $applied[] = (int) $branch->id;
        }

        return [$applied, $rejected];
    }

    private function rejectReason(int $branchId, string $currency, User $user, string $type, bool $allScope = false): ?string
    {
        $branch = Branch::query()->find($branchId);
        if (!$branch) {
            return 'not_found';
        }
        if ($branch->archived_at) {
            return 'archived';
        }
        if (($branch->status ?? 'active') !== 'active') {
            return 'inactive';
        }
        if (!(bool) $branch->can_set_pricing) {
            return 'can_set_pricing_false';
        }
        $supports = $currency === 'dinar' ? (bool) $branch->supports_dinar : (bool) $branch->supports_toman;
        if (!$supports) {
            return 'currency_unsupported';
        }
        if ($type === PriceChangeBatch::TYPE_SELLING && !BranchAccess::canChangeSellingPrice($user, $branchId, false)) {
            return $allScope ? 'not_permitted' : 'not_permitted';
        }

        return null;
    }

    private function marginWarning(int $bookId, int $branchId, string $currency, ?string $newSell, ?string $newCost): ?string
    {
        $sell = $newSell ?? $this->selling->current($bookId, $branchId, $currency)['price'];
        $lot = StockLot::query()
            ->sellable()
            ->where('book_id', $bookId)
            ->where('branch_id', $branchId)
            ->where('currency', $currency)
            ->where('ownership_type', 'consignment')
            ->orderBy('id')
            ->first();
        $cost = $newCost ?? ($lot ? $lot->effectivePayableUnitCost() : null);
        if ($cost === null || $sell === null) {
            return null;
        }
        if (Money::cmp($sell, $cost) < 0) {
            return 'margin_negative';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function presentPreview(array $plan): array
    {
        return [
            'type' => $plan['type'],
            'book_id' => $plan['book_id'],
            'currency' => $plan['currency'],
            'scope' => $plan['scope'],
            'new_amount' => $plan['new_amount'],
            'applied' => $plan['applied'],
            'rejected' => $plan['rejected'],
            'preview_hash' => $plan['preview_hash'],
            'affected_lots' => collect($plan['applied'])->sum(fn ($row) => $row['lots_count'] ?? 0),
            'unsold_qty' => collect($plan['applied'])->sum(fn ($row) => $row['unsold_qty'] ?? 0),
            'reserved_qty' => collect($plan['applied'])->sum(fn ($row) => $row['reserved_qty'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentApplied(PriceChangeBatch $batch): array
    {
        $batch->load(['sellingRevisions.branch', 'consignmentRevisions.lots']);

        return [
            'id' => $batch->id,
            'type' => $batch->type,
            'scope' => $batch->scope,
            'status' => $batch->status,
            'reason' => $batch->reason,
            'applied_at' => $batch->applied_at,
            'idempotency_key' => $batch->idempotency_key,
            'rejected' => $batch->meta['rejected'] ?? [],
            'selling_revisions' => $batch->sellingRevisions,
            'consignment_revisions' => $batch->consignmentRevisions,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestHash(array $payload): string
    {
        $copy = $payload;
        unset($copy['preview_hash'], $copy['idempotency_key']);
        ksort($copy);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertImmediate(array $payload): void
    {
        if (empty($payload['effective_at'])) {
            return;
        }
        if (strtotime((string) $payload['effective_at']) > time() + 2) {
            throw new DomainException('تغییر زمان‌بندی‌شده در این نسخه پشتیبانی نمی‌شود', 422, [
                'error' => 'future_effective_at',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function overrideAmounts(array $payload, string $type): array
    {
        $field = $type === PriceChangeBatch::TYPE_SELLING ? 'new_price' : 'new_cost';
        $map = [];
        foreach ($payload['branch_overrides'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['branch_id'] ?? 0);
            if ($id <= 0 || !array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                continue;
            }
            $value = Money::of($row[$field]);
            if (Money::isZero($value) || Money::isNegative($value)) {
                throw new DomainException('مبلغ جدید باید بزرگ‌تر از صفر باشد', 422);
            }
            $map[$id] = $value;
        }
        ksort($map);

        return $map;
    }
}
