<?php

namespace App\Services\BranchShare;

use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\BranchSalesShareBatch;
use App\Models\BranchSalesShareRule;
use App\Models\BranchShareReturnAllocation;
use App\Models\CustomerReturn;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemBranchShare;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Support\BranchShareFlags;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BranchSalesShareService
{
    public const BASIS = BranchSalesShareRule::BASIS_NET_REALIZED;

    public function snapshotSale(Invoice $invoice): void
    {
        if (!BranchShareFlags::enabled()) {
            return;
        }
        if ((string) ($invoice->type ?? 'sale') !== 'sale') {
            return;
        }

        $invoice->loadMissing('items');
        $at = $invoice->sold_at ? Carbon::parse($invoice->sold_at) : now();
        $branchId = (int) $invoice->branch_id;
        $currency = (string) $invoice->currency;
        $rule = $this->resolveRule($branchId, $at, true);
        if (!$rule) {
            return;
        }

        foreach ($invoice->items as $item) {
            $this->persistSaleShare($invoice, $item, $rule, $branchId, $currency);
        }
    }

    public function snapshotReturn(CustomerReturn $return): void
    {
        if (!BranchShareFlags::enabled()) {
            return;
        }

        $rows = $return->lotAllocations()->with(['saleAllocation.invoiceItem'])->get();
        foreach ($rows as $row) {
            if (BranchShareReturnAllocation::query()->where('return_allocation_id', $row->id)->exists()) {
                continue;
            }
            $item = $row->saleAllocation?->invoiceItem;
            if (!$item) {
                continue;
            }
            $share = InvoiceItemBranchShare::query()
                ->where('invoice_item_id', $item->id)
                ->lockForUpdate()
                ->first();
            if (!$share) {
                continue;
            }
            $this->persistReturnShare($return, (int) $row->id, $share, (int) $row->quantity);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload, User $user): array
    {
        $this->assertCanMutate($user);

        return $this->presentPreview($this->plan($payload, $user));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload, User $user): array
    {
        $this->assertCanMutate($user);
        $this->assertEnabled();

        $idempotencyKey = (string) $payload['idempotency_key'];
        $requestHash = $this->requestHash($payload);
        $existing = BranchSalesShareBatch::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->request_hash !== $requestHash) {
                throw new DomainException('کلید تکرار با محتوای متفاوت قبلاً استفاده شده است', 409, [
                    'error' => 'idempotency_conflict',
                ]);
            }

            return $this->presentApplied($existing);
        }

        return DB::transaction(function () use ($payload, $user, $idempotencyKey, $requestHash) {
            $locked = BranchSalesShareBatch::query()
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
            if ($plan['has_overlap']) {
                throw new DomainException('بازه زمانی قوانین سهم شعبه تداخل دارد', 409, [
                    'error' => 'rule_overlap',
                    'warnings' => $plan['warnings'],
                ]);
            }

            $batch = BranchSalesShareBatch::create([
                'scope' => $plan['scope'],
                'status' => BranchSalesShareBatch::STATUS_DRAFT,
                'rate_bps' => $plan['rate_bps'],
                'calculation_basis' => self::BASIS,
                'reason' => $plan['reason'],
                'effective_from' => $plan['effective_from'],
                'created_by' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'meta' => [
                    'rows' => $plan['rows'],
                    'warnings' => $plan['warnings'],
                    'past_sales_unchanged' => true,
                ],
            ]);

            try {
                foreach ($plan['rows'] as $row) {
                    $branchId = $row['branch_id'];
                    $scopeKey = BranchSalesShareRule::scopeKey($branchId);
                    BranchSalesShareRule::query()->where('scope_key', $scopeKey)->lockForUpdate()->get();
                    if (!empty($row['close_rule_id'])) {
                        BranchSalesShareRule::query()
                            ->whereKey($row['close_rule_id'])
                            ->whereNull('effective_to')
                            ->update(['effective_to' => $plan['effective_from']]);
                    }
                    BranchSalesShareRule::create([
                        'batch_id' => $batch->id,
                        'branch_id' => $branchId,
                        'scope_key' => $scopeKey,
                        'rate_bps' => $plan['rate_bps'],
                        'calculation_basis' => self::BASIS,
                        'effective_from' => $plan['effective_from'],
                        'effective_to' => null,
                        'reason' => $plan['reason'],
                        'created_by' => $user->id,
                        'idempotency_key' => $idempotencyKey.':'.$scopeKey,
                    ]);
                }
                $batch->update([
                    'status' => BranchSalesShareBatch::STATUS_APPLIED,
                    'applied_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $batch->update(['status' => BranchSalesShareBatch::STATUS_FAILED]);
                throw $e;
            }

            ActivityLogger::record(
                'finance',
                'branch_sales_share_applied',
                'اعمال درصد سهم شعب',
                $batch,
                [
                    'scope' => $plan['scope'],
                    'rate_bps' => $plan['rate_bps'],
                    'effective_from' => $plan['effective_from']->toIso8601String(),
                    'new_rule_count' => $plan['new_rule_count'],
                ],
                null,
                $user->id,
            );

            return $this->presentApplied($batch->fresh(['rules.branch', 'creator:id,name']));
        });
    }

    public function resolveRule(int $branchId, Carbon $at, bool $lock = false): ?BranchSalesShareRule
    {
        $query = BranchSalesShareRule::query()
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->where('effective_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $at);
            })
            ->orderByRaw('branch_id is null')
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $matches = $query->get();
        $specific = $matches->first(fn (BranchSalesShareRule $rule) => $rule->branch_id !== null);

        return $specific ?: $matches->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function currentRules(?User $user = null, ?int $branchId = null): array
    {
        $this->assertCanView($user, $branchId);
        $at = now();
        $visible = $this->visibleBranchModels($user, $branchId);
        $global = $this->coveringRule(BranchSalesShareRule::GLOBAL_SCOPE, $at);
        $rows = [];
        foreach ($visible as $branch) {
            $rule = $this->resolveRule((int) $branch->id, $at);
            $rows[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'rate' => $rule ? $this->bpsToRate((int) $rule->rate_bps) : null,
                'rate_bps' => $rule ? (int) $rule->rate_bps : null,
                'rule_id' => $rule?->id,
                'source' => $rule === null ? 'none' : ($rule->branch_id === null ? 'default' : 'branch'),
                'effective_from' => $rule?->effective_from?->toIso8601String(),
                'default_rate' => $global ? $this->bpsToRate((int) $global->rate_bps) : null,
            ];
        }

        return [
            'as_of' => $at->toIso8601String(),
            'default_rule' => $global ? $this->presentRule($global) : null,
            'branches' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function history(?User $user = null, ?int $branchId = null): array
    {
        $this->assertCanView($user, $branchId);
        $query = BranchSalesShareBatch::query()
            ->with(['rules.branch:id,name', 'creator:id,name'])
            ->orderByDesc('id')
            ->limit(200);
        if ($branchId) {
            $query->whereHas('rules', function ($r) use ($branchId) {
                $r->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        $history = [];
        foreach ($query->get() as $batch) {
            foreach ($batch->rules as $rule) {
                if ($branchId && $rule->branch_id !== null && (int) $rule->branch_id !== $branchId) {
                    continue;
                }
                $history[] = [
                    'id' => $rule->id,
                    'batch_id' => $batch->id,
                    'branch_id' => $rule->branch_id,
                    'branch_name' => $rule->branch_id === null
                        ? 'همه شعب'
                        : ($rule->branch?->name ?? '#'.$rule->branch_id),
                    'previous_rate' => $this->previousRateFromMeta($batch, $rule->branch_id),
                    'new_rate' => $this->bpsToRate((int) $rule->rate_bps),
                    'rate_bps' => (int) $rule->rate_bps,
                    'effective_from' => $rule->effective_from?->toIso8601String(),
                    'effective_to' => $rule->effective_to?->toIso8601String(),
                    'created_by' => $batch->creator?->name,
                    'reason' => $rule->reason,
                    'created_at' => $rule->created_at?->toIso8601String(),
                ];
            }
        }

        return ['history' => $history];
    }

    /**
     * @param  array<string, string>  $operatingProfit
     * @return array<string, mixed>
     */
    public function reportForBranch(int $branchId, string $dateFrom, string $dateTo, array $operatingProfit = []): array
    {
        $start = Carbon::parse($dateFrom)->startOfDay();
        $end = Carbon::parse($dateTo)->endOfDay();
        $currencies = [];
        foreach (['toman', 'dinar'] as $currency) {
            $block = $this->currencyTotals($branchId, $start, $end, $currency);
            $profit = Money::of($operatingProfit[$currency] ?? 0);
            $block['operating_profit'] = $profit;
            $block['remaining_profit_after_share'] = Money::sub($profit, $block['net_share']);
            $block['remaining_profit_label'] = 'شاخص مدیریتی';
            $currencies[$currency] = $block;
        }

        return [
            'mode' => 'managerial',
            'official_net_profit_unchanged' => true,
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'currencies' => $currencies,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?User $user, string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        $this->assertCanView($user, $branchId);
        $branches = $this->visibleBranchModels($user, $branchId);
        $totals = [
            'toman' => $this->emptyCurrencyTotals(),
            'dinar' => $this->emptyCurrencyTotals(),
        ];
        $rows = [];
        foreach ($branches as $branch) {
            $report = $this->reportForBranch((int) $branch->id, $dateFrom, $dateTo);
            $rows[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'currencies' => $report['currencies'],
            ];
            foreach (['toman', 'dinar'] as $currency) {
                $block = $report['currencies'][$currency];
                foreach (['gross_sales', 'discount', 'net_sales', 'gross_share', 'share_returns', 'net_share'] as $key) {
                    $totals[$currency][$key] = Money::add($totals[$currency][$key], $block[$key]);
                }
                foreach ($block['rates_used'] as $rate) {
                    if (!in_array($rate, $totals[$currency]['rates_used'], true)) {
                        $totals[$currency]['rates_used'][] = $rate;
                    }
                }
            }
        }

        return [
            'mode' => 'managerial',
            'official_net_profit_unchanged' => true,
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'totals' => $totals,
            'branches' => $rows,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function invoiceRows(?User $user, ?int $branchId, string $dateFrom, string $dateTo, ?string $currency = null): array
    {
        $this->assertCanView($user, $branchId);
        $start = Carbon::parse($dateFrom)->startOfDay();
        $end = Carbon::parse($dateTo)->endOfDay();
        $query = InvoiceItemBranchShare::query()
            ->with(['invoice:id,invoice_number,sold_at,currency,branch_id', 'returnAllocations'])
            ->whereHas('invoice', fn ($q) => $q->whereBetween('sold_at', [$start, $end]))
            ->orderByDesc('id')
            ->limit(500);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        } else {
            $ids = BranchAccess::visibleBranchIds($user);
            if ($ids !== null) {
                $query->whereIn('branch_id', $ids ?: [0]);
            }
        }
        if ($currency) {
            $query->where('currency', $currency);
        }

        $rows = [];
        foreach ($query->get() as $share) {
            $reversed = '0.00';
            foreach ($share->returnAllocations as $alloc) {
                $reversed = Money::add($reversed, $alloc->reversed_share_amount);
            }
            $rows[] = [
                'invoice_id' => $share->invoice_id,
                'invoice_number' => $share->invoice?->invoice_number,
                'invoice_item_id' => $share->invoice_item_id,
                'branch_id' => $share->branch_id,
                'currency' => $share->currency,
                'sold_at' => $share->invoice?->sold_at?->toIso8601String(),
                'net_sales_amount' => Money::of($share->net_sales_amount),
                'rate_bps' => (int) $share->rate_bps,
                'rate' => $this->bpsToRate((int) $share->rate_bps),
                'share_amount' => Money::of($share->share_amount),
                'share_returns' => $reversed,
                'net_share' => Money::sub($share->share_amount, $reversed),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function backfillPreview(?Carbon $from = null, ?int $branchId = null): array
    {
        $from ??= now();
        $query = Invoice::query()
            ->where(function ($q) {
                $q->where('type', 'sale')->orWhereNull('type');
            })
            ->where('sold_at', '>=', $from);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        $invoices = $query->with('items.branchShare')->get();
        $would = 0;
        foreach ($invoices as $invoice) {
            foreach ($invoice->items as $item) {
                if (!$item->branchShare) {
                    $would++;
                }
            }
        }

        return [
            'dry_run' => true,
            'would_snapshot_lines' => $would,
            'from' => $from->toIso8601String(),
            'writes_journals' => false,
            'rewrites_invoices' => false,
        ];
    }

    public function netSalesOf(InvoiceItem $item): string
    {
        $unit = Money::sub($item->actual_price ?? 0, $item->discount ?? 0);

        return Money::mul($unit, (int) $item->quantity);
    }

    public function bpsToRate(int $bps): string
    {
        return bcdiv((string) $bps, '100', 2);
    }

    public function rateToBps(mixed $rate): int
    {
        $normalized = Money::of($rate);
        if (Money::isNegative($normalized)) {
            throw new DomainException('درصد سهم نمی‌تواند منفی باشد', 422);
        }
        if (Money::cmp($normalized, '100') > 0) {
            throw new DomainException('درصد سهم نمی‌تواند بیشتر از ۱۰۰ باشد', 422);
        }
        $bps = (int) bcmul($normalized, '100', 0);
        if ($bps < 0 || $bps > 10000) {
            throw new DomainException('درصد سهم نامعتبر است', 422);
        }

        return $bps;
    }

    private function persistSaleShare(
        Invoice $invoice,
        InvoiceItem $item,
        BranchSalesShareRule $rule,
        int $branchId,
        string $currency
    ): void {
        if (InvoiceItemBranchShare::query()->where('invoice_item_id', $item->id)->exists()) {
            return;
        }
        $qty = (int) $item->quantity;
        if ($qty <= 0) {
            return;
        }
        $net = $this->netSalesOf($item);
        try {
            InvoiceItemBranchShare::create([
                'invoice_id' => $invoice->id,
                'invoice_item_id' => $item->id,
                'branch_id' => $branchId,
                'currency' => $currency,
                'quantity' => $qty,
                'net_sales_amount' => $net,
                'rate_bps' => (int) $rule->rate_bps,
                'share_amount' => Money::mulBps($net, (int) $rule->rate_bps),
                'rule_id' => $rule->id,
                'calculation_basis' => (string) $rule->calculation_basis,
            ]);
        } catch (UniqueConstraintViolationException) {
        }
    }

    private function persistReturnShare(
        CustomerReturn $return,
        int $allocationId,
        InvoiceItemBranchShare $share,
        int $returnedQty
    ): void {
        $prior = BranchShareReturnAllocation::query()->where('sale_share_id', $share->id)->get();
        $alreadyQty = (int) $prior->sum('returned_quantity');
        $alreadyShare = '0.00';
        $alreadyBase = '0.00';
        foreach ($prior as $row) {
            $alreadyShare = Money::add($alreadyShare, $row->reversed_share_amount);
            $alreadyBase = Money::add($alreadyBase, $row->reversed_base_amount);
        }

        $originalQty = (int) $share->quantity;
        $remainingQty = $originalQty - $alreadyQty;
        if ($returnedQty > $remainingQty) {
            throw new DomainException('مجموع برگشت سهم شعبه از سهم فروش بیشتر است', 422);
        }
        $remainingShare = Money::sub($share->share_amount, $alreadyShare);
        $remainingBase = Money::sub($share->net_sales_amount, $alreadyBase);
        if ($remainingQty === $returnedQty) {
            $reversedShare = $remainingShare;
            $reversedBase = $remainingBase;
        } else {
            $reversedShare = Money::min(Money::proportion($share->share_amount, $returnedQty, $originalQty), $remainingShare);
            $reversedBase = Money::min(Money::proportion($share->net_sales_amount, $returnedQty, $originalQty), $remainingBase);
        }

        try {
            BranchShareReturnAllocation::create([
                'customer_return_id' => $return->id,
                'return_allocation_id' => $allocationId,
                'sale_share_id' => $share->id,
                'branch_id' => (int) $share->branch_id,
                'currency' => (string) $share->currency,
                'returned_quantity' => $returnedQty,
                'reversed_base_amount' => $reversedBase,
                'reversed_share_amount' => $reversedShare,
            ]);
        } catch (UniqueConstraintViolationException) {
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function plan(array $payload, User $user, bool $forApply = false): array
    {
        $this->assertEnabled();
        $hasIds = !empty($payload['branch_ids']);
        $scope = (string) ($payload['scope'] ?? ($hasIds ? BranchSalesShareBatch::SCOPE_SELECTED : BranchSalesShareBatch::SCOPE_ALL));
        if (!in_array($scope, [BranchSalesShareBatch::SCOPE_ALL, BranchSalesShareBatch::SCOPE_SELECTED], true)) {
            $scope = $hasIds ? BranchSalesShareBatch::SCOPE_SELECTED : BranchSalesShareBatch::SCOPE_ALL;
        }
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException('دلیل تغییر درصد سهم الزامی است', 422);
        }
        $rateBps = $this->rateToBps($payload['rate'] ?? 0);
        $effectiveFrom = Carbon::parse((string) ($payload['effective_from'] ?? now()));
        $targets = $this->resolveTargets($scope, $payload['branch_ids'] ?? [], $user);
        $rows = [];
        $warnings = [];
        $hasOverlap = false;
        foreach ($targets as $branchId) {
            $analysis = $this->analyzeScope($branchId, $effectiveFrom, $forApply);
            $previous = $analysis['current'];
            $name = $branchId === null
                ? 'همه شعب (قانون پیش‌فرض)'
                : (Branch::query()->find($branchId)?->name ?? '#'.$branchId);
            if ($analysis['overlap']) {
                $hasOverlap = true;
                $warnings[] = 'تداخل بازه برای '.$name;
            }
            $rows[] = [
                'branch_id' => $branchId,
                'branch_name' => $name,
                'previous_rate' => $previous ? $this->bpsToRate((int) $previous->rate_bps) : null,
                'previous_rate_bps' => $previous ? (int) $previous->rate_bps : null,
                'new_rate' => $this->bpsToRate($rateBps),
                'new_rate_bps' => $rateBps,
                'effective_from' => $effectiveFrom->toIso8601String(),
                'closes_previous' => $analysis['close_rule_id'] !== null,
                'close_rule_id' => $analysis['close_rule_id'],
                'overlap' => $analysis['overlap'],
            ];
        }

        $plan = [
            'scope' => $scope,
            'rate' => $this->bpsToRate($rateBps),
            'rate_bps' => $rateBps,
            'effective_from' => $effectiveFrom,
            'reason' => $reason,
            'calculation_basis' => self::BASIS,
            'rows' => $rows,
            'new_rule_count' => count($rows),
            'past_sales_unchanged' => true,
            'has_overlap' => $hasOverlap,
            'warnings' => $warnings,
        ];
        $plan['preview_hash'] = hash('sha256', json_encode($this->hashablePlan($plan), JSON_UNESCAPED_UNICODE));

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function hashablePlan(array $plan): array
    {
        return [
            'scope' => $plan['scope'],
            'rate_bps' => $plan['rate_bps'],
            'effective_from' => $plan['effective_from'] instanceof Carbon
                ? $plan['effective_from']->toIso8601String()
                : $plan['effective_from'],
            'reason' => $plan['reason'],
            'rows' => collect($plan['rows'])->map(fn ($row) => [
                'branch_id' => $row['branch_id'],
                'new_rate_bps' => $row['new_rate_bps'],
                'close_rule_id' => $row['close_rule_id'],
                'overlap' => $row['overlap'],
            ])->all(),
        ];
    }

    /**
     * @return array{current: ?BranchSalesShareRule, close_rule_id: ?int, overlap: bool}
     */
    private function analyzeScope(?int $branchId, Carbon $effectiveFrom, bool $lock): array
    {
        $scopeKey = BranchSalesShareRule::scopeKey($branchId);
        $query = BranchSalesShareRule::query()->where('scope_key', $scopeKey)->orderBy('effective_from');
        if ($lock) {
            $query->lockForUpdate();
        }
        $rules = $query->get();
        $current = $this->pickCovering($rules, $effectiveFrom->copy()->subSecond());
        $closeId = null;
        $overlap = false;
        foreach ($rules as $rule) {
            if (!$this->overlapsOpenEnded($rule, $effectiveFrom)) {
                continue;
            }
            $canClose = $rule->effective_to === null && $rule->effective_from->lt($effectiveFrom);
            if ($canClose) {
                $closeId = (int) $rule->id;
                continue;
            }
            $overlap = true;
        }

        return [
            'current' => $current,
            'close_rule_id' => $overlap ? null : $closeId,
            'overlap' => $overlap,
        ];
    }

    /** New rule is half-open [newFrom, +∞). Existing is [from, to). */
    private function overlapsOpenEnded(BranchSalesShareRule $rule, Carbon $newFrom): bool
    {
        $ruleFrom = Carbon::parse($rule->effective_from);
        $ruleTo = $rule->effective_to ? Carbon::parse($rule->effective_to) : null;
        if ($ruleTo === null) {
            return $ruleFrom->lte($newFrom) || $newFrom->lte($ruleFrom);
        }

        return $newFrom->lt($ruleTo) && $ruleFrom->lt($newFrom->copy()->addCentury());
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BranchSalesShareRule>  $rules
     */
    private function pickCovering($rules, Carbon $at): ?BranchSalesShareRule
    {
        return $rules->first(function (BranchSalesShareRule $rule) use ($at) {
            if ($rule->effective_from->gt($at)) {
                return false;
            }
            if ($rule->effective_to === null) {
                return true;
            }

            return $rule->effective_to->gt($at);
        });
    }

    private function coveringRule(string $scopeKey, Carbon $at): ?BranchSalesShareRule
    {
        return BranchSalesShareRule::query()
            ->where('scope_key', $scopeKey)
            ->where('effective_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $at);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<int, mixed>  $branchIds
     * @return array<int, int|null>
     */
    private function resolveTargets(string $scope, array $branchIds, User $user): array
    {
        if ($scope === BranchSalesShareBatch::SCOPE_ALL) {
            return [null];
        }
        $ids = collect($branchIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            throw new DomainException('انتخاب شعبه الزامی است', 422);
        }
        foreach ($ids as $id) {
            if (!Branch::query()->whereKey($id)->exists()) {
                throw new DomainException('شعبه نامعتبر است', 422);
            }
            BranchAccess::assertBranchAllowed($user, (int) $id);
        }

        return $ids->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPreview(array $plan): array
    {
        return [
            'preview_hash' => $plan['preview_hash'],
            'scope' => $plan['scope'],
            'rate' => $plan['rate'],
            'rate_bps' => $plan['rate_bps'],
            'effective_from' => $plan['effective_from']->toIso8601String(),
            'reason' => $plan['reason'],
            'new_rule_count' => $plan['new_rule_count'],
            'past_sales_unchanged' => true,
            'has_overlap' => $plan['has_overlap'],
            'warnings' => $plan['warnings'],
            'branches' => $plan['rows'],
            'note' => 'فروش‌های قبلی تغییر نمی‌کنند. سهم فقط از تاریخ شروع به بعد snapshot می‌شود.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentApplied(BranchSalesShareBatch $batch): array
    {
        $batch->loadMissing(['rules.branch', 'creator:id,name']);

        return [
            'id' => $batch->id,
            'status' => $batch->status,
            'scope' => $batch->scope,
            'rate' => $this->bpsToRate((int) $batch->rate_bps),
            'rate_bps' => (int) $batch->rate_bps,
            'effective_from' => $batch->effective_from?->toIso8601String(),
            'reason' => $batch->reason,
            'idempotency_key' => $batch->idempotency_key,
            'rules' => $batch->rules->map(fn (BranchSalesShareRule $rule) => $this->presentRule($rule))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRule(BranchSalesShareRule $rule): array
    {
        return [
            'id' => $rule->id,
            'branch_id' => $rule->branch_id,
            'branch_name' => $rule->branch_id === null ? 'همه شعب' : $rule->branch?->name,
            'rate' => $this->bpsToRate((int) $rule->rate_bps),
            'rate_bps' => (int) $rule->rate_bps,
            'calculation_basis' => $rule->calculation_basis,
            'effective_from' => $rule->effective_from?->toIso8601String(),
            'effective_to' => $rule->effective_to?->toIso8601String(),
            'reason' => $rule->reason,
        ];
    }

    private function previousRateFromMeta(BranchSalesShareBatch $batch, ?int $branchId): ?string
    {
        foreach (($batch->meta['rows'] ?? []) as $row) {
            $rowBranch = $row['branch_id'] ?? null;
            if ($rowBranch === $branchId || ((int) $rowBranch === (int) $branchId && $branchId !== null)) {
                return $row['previous_rate'] ?? null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function currencyTotals(int $branchId, Carbon $start, Carbon $end, string $currency): array
    {
        $shares = InvoiceItemBranchShare::query()
            ->with(['invoiceItem', 'returnAllocations.customerReturn'])
            ->where('branch_id', $branchId)
            ->where('currency', $currency)
            ->whereHas('invoice', fn ($q) => $q->whereBetween('sold_at', [$start, $end]))
            ->get();

        $gross = '0.00';
        $discount = '0.00';
        $net = '0.00';
        $grossShare = '0.00';
        $shareReturns = '0.00';
        $rates = [];
        foreach ($shares as $share) {
            $item = $share->invoiceItem;
            $qty = (int) ($item?->quantity ?? $share->quantity);
            $gross = Money::add($gross, Money::mul($item?->actual_price ?? 0, $qty));
            $discount = Money::add($discount, Money::mul($item?->discount ?? 0, $qty));
            $net = Money::add($net, $share->net_sales_amount);
            $grossShare = Money::add($grossShare, $share->share_amount);
            $rate = $this->bpsToRate((int) $share->rate_bps);
            if (!in_array($rate, $rates, true)) {
                $rates[] = $rate;
            }
            foreach ($share->returnAllocations as $alloc) {
                $returnedAt = $alloc->customerReturn?->returned_at;
                if ($returnedAt && (Carbon::parse($returnedAt)->lt($start) || Carbon::parse($returnedAt)->gt($end))) {
                    continue;
                }
                $shareReturns = Money::add($shareReturns, $alloc->reversed_share_amount);
            }
        }

        return [
            'gross_sales' => $gross,
            'discount' => $discount,
            'net_sales' => $net,
            'gross_share' => $grossShare,
            'share_returns' => $shareReturns,
            'net_share' => Money::sub($grossShare, $shareReturns),
            'rates_used' => $rates,
            'rates_used_bps' => array_map(fn (string $rate) => $this->rateToBps($rate), $rates),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCurrencyTotals(): array
    {
        return [
            'gross_sales' => '0.00',
            'discount' => '0.00',
            'net_sales' => '0.00',
            'gross_share' => '0.00',
            'share_returns' => '0.00',
            'net_share' => '0.00',
            'rates_used' => [],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Branch>
     */
    private function visibleBranchModels(?User $user, ?int $branchId)
    {
        $query = Branch::query()->where('status', 'active')->orderBy('id');
        if ($branchId) {
            BranchAccess::assertBranchAllowed($user, $branchId);
            $query->whereKey($branchId);
        } else {
            $ids = BranchAccess::visibleBranchIds($user);
            if ($ids !== null) {
                $query->whereIn('id', $ids ?: [0]);
            }
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestHash(array $payload): string
    {
        $canonical = [
            'scope' => $payload['scope'] ?? null,
            'branch_ids' => $payload['branch_ids'] ?? [],
            'rate' => Money::of($payload['rate'] ?? 0),
            'effective_from' => (string) ($payload['effective_from'] ?? ''),
            'reason' => trim((string) ($payload['reason'] ?? '')),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    private function assertEnabled(): void
    {
        if (!BranchShareFlags::enabled()) {
            throw new DomainException('سهم مدیریتی شعب غیرفعال است', 403, ['error' => 'feature_disabled']);
        }
    }

    private function assertCanMutate(?User $user): void
    {
        if (!BranchAccess::isAdmin($user)) {
            BranchAccess::deny('تغییر درصد سهم شعب فقط برای مدیر مجاز است');
        }
    }

    private function assertCanView(?User $user, ?int $branchId = null): void
    {
        BranchAccess::assertCanViewFinancialReports($user);
        if ($user && $user->role === 'branch_manager') {
            $own = $user->branch_id ? (int) $user->branch_id : null;
            if ($branchId && $own !== $branchId) {
                BranchAccess::deny('اجازه مشاهده سهم شعبه دیگر را ندارید');
            }
        }
        if ($branchId) {
            BranchAccess::assertBranchAllowed($user, $branchId);
        }
    }
}
