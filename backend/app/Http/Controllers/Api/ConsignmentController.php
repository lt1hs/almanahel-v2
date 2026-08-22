<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\ConsignmentReceipt;
use App\Models\ConsignmentReceiptItem;
use App\Models\Inventory;
use App\Models\Settlement;
use App\Services\Ledger\FinancePostingGateway;
use App\Services\Ledger\SupplierCheckTransition;
use App\Services\Settlement\PayableSnapshot;
use App\Services\Settlement\SnapshotPayable;
use App\Services\Settlement\PeriodSettlement;
use App\Services\Settlement\SettlementRecorder;
use App\Services\Settlement\SupplierPayable;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Support\IntakePolicy;
use App\Support\Money;
use App\Support\StockMovementLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsignmentController extends Controller
{
    private function scopeOperationalReceipts($query, $user, Request $request): void
    {
        $branchScope = BranchAccess::resolveOperationalConsignmentScope(
            $user,
            $request->filled('branch_id') ? (int) $request->branch_id : null,
            $request->boolean('aggregate')
        );
        if ($branchScope !== null) {
            $query->where('branch_id', $branchScope);
        }
    }

    private function assertCanAccessReceipt($user, ConsignmentReceipt $receipt): void
    {
        BranchAccess::assertCanAccessOperationalConsignmentReceipt($user, (int) $receipt->branch_id);
    }

    public function index(Request $request)
    {
        // Heal receipts left unsettled after books were cascade-deleted
        ConsignmentReceipt::closeOrphans();

        $query = ConsignmentReceipt::query()
            ->select([
                'id',
                'receipt_number',
                'supplier_id',
                'supplier_account_id',
                'branch_id',
                'user_id',
                'status',
                'currency',
                'total_value',
                'settled_amount',
                'received_at',
                'created_at',
            ])
            ->with([
                'supplier:id,name,phone',
                'branch:id,name',
            ])
            ->withCount('items');

        $this->scopeOperationalReceipts($query, $request->user(), $request);

        if ($request->filled('supplier_account_id')) {
            $query->where('supplier_account_id', $request->supplier_account_id);
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('branch_id') && $request->boolean('aggregate')) {
            $query->where('branch_id', (int) $request->branch_id);
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('receipt_number', 'like', $q)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $q));
            });
        }

        $profiles = [];
        $profile = function (ConsignmentReceipt $receipt) use (&$profiles): array {
            return $profiles[$receipt->id] ??= $this->receiptFinancialProfile($receipt);
        };

        // Receipt.status is a legacy persistence field and cannot distinguish a newly
        // received (not yet payable) receipt from a sold, unpaid receipt. Filter on the
        // actual immutable sale/gift payable snapshots instead.
        $requestedStatus = $request->input('payable_status', $request->input('status'));
        if ($requestedStatus && $requestedStatus !== 'all') {
            $candidateIds = (clone $query)
                ->with('items:id,consignment_receipt_id,quantity_received,quantity_sold,quantity_returned')
                ->get()
                ->filter(fn (ConsignmentReceipt $receipt) => $profile($receipt)['payable_status'] === $requestedStatus)
                ->pluck('id');

            $candidateIds->isEmpty()
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('id', $candidateIds->all());
        }

        $summary = [
            'receipts_count' => 0,
            'not_due_count' => 0,
            'unsettled_count' => 0,
            'partially_settled_count' => 0,
            'settled_count' => 0,
            'currencies' => [
                'toman' => $this->emptyReceiptMoneySummary(),
                'dinar' => $this->emptyReceiptMoneySummary(),
            ],
        ];

        $summaryReceipts = (clone $query)
            ->with('items:id,consignment_receipt_id,quantity_received,quantity_sold,quantity_returned')
            ->get();
        foreach ($summaryReceipts as $receipt) {
            $financial = $profile($receipt);
            $summary['receipts_count']++;
            $summary[$financial['payable_status'].'_count']++;
            $currency = $receipt->currency;
            foreach (['inventory_value', 'payable_generated', 'payable_settled', 'payable_outstanding'] as $field) {
                $summary['currencies'][$currency][$field] = Money::add(
                    $summary['currencies'][$currency][$field],
                    $financial[$field]
                );
            }
        }

        $paginator = $query->latest('received_at')->latest('id')->paginate(20);
        $paginator->getCollection()->transform(function (ConsignmentReceipt $receipt) use ($profile) {
            foreach ($profile($receipt) as $key => $value) {
                $receipt->setAttribute($key, $value);
            }

            return $receipt;
        });

        $payload = $paginator->toArray();
        $payload['summary'] = $summary;

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_account_id' => 'nullable|exists:supplier_accounts,id',
            'supplier_id'      => 'required_without:supplier_account_id|nullable|exists:suppliers,id',
            'branch_id'        => 'required|exists:branches,id',
            'currency'         => 'required|in:toman,dinar',
            'received_at'      => 'required|date',
            'notes'            => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.book_id'       => 'required|exists:books,id',
            'items.*.quantity'      => 'required|integer|min:1',
            'items.*.cost_price'    => 'required|numeric|min:0.01',
            'items.*.selling_price' => 'required|numeric|min:0',
            'items.*.price_toman'   => 'nullable|numeric|min:0',
            'items.*.price_dinar'   => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $validated) {
        $iraqOnly = false;
        if (!empty($validated['items'])) {
            $firstBook = \App\Models\Book::find($validated['items'][0]['book_id']);
            $iraqOnly = (bool) ($firstBook?->iraq_only);
        }

        if ($denied = IntakePolicy::assertIntakeAllowed($request->user(), (int) $validated['branch_id'], $iraqOnly)) {
            return $denied;
        }

        BranchAccess::assertCanMutateInBranch($request->user(), (int) $validated['branch_id']);
        if ($request->boolean('aggregate')) {
            return response()->json(['message' => 'حالت تجمیعی برای ثبت مجاز نیست', 'error' => 'aggregate_not_allowed'], 422);
        }

        $resolved = app(\App\Services\Suppliers\SupplierAccountResolver::class)->resolveForMutation(
            (int) $validated['branch_id'],
            $validated['supplier_account_id'] ?? null,
            $validated['supplier_id'] ?? null,
            true
        );
        $validated['supplier_id'] = $resolved['supplier_id'];
        $validated['supplier_account_id'] = $resolved['account']->id;
        if (!$validated['supplier_id']) {
            throw new \App\Exceptions\DomainException('رسید امانی به تأمین‌کننده متعارف نیاز دارد', 422, [
                'error' => 'supplier_account_unresolved',
            ]);
        }

        $totalValue = 0;
        foreach ($validated['items'] as $item) {
            $totalValue += $item['cost_price'] * $item['quantity'];
        }

        $receipt = ConsignmentReceipt::create([
            'supplier_id'    => $validated['supplier_id'],
            'supplier_account_id' => $validated['supplier_account_id'],
            'branch_id'      => $validated['branch_id'],
            'user_id'        => $request->user()->id,
            'receipt_number' => 'CR-' . strtoupper(Str::random(8)),
            'status'         => 'unsettled',
            'currency'       => $validated['currency'],
            'total_value'    => $totalValue,
            'settled_amount' => 0,
            'received_at'    => $validated['received_at'],
            'notes'          => $validated['notes'] ?? null,
            'payable_basis'  => PayableSnapshot::BASIS_FULL_UNIT_COST,
            'payable_rate'   => '1.0000',
            'payable_rule_source' => 'intake',
            'payable_rule_stamped_at' => now(),
        ]);

        foreach ($validated['items'] as $item) {
            $receiptItem = ConsignmentReceiptItem::create([
                'consignment_receipt_id' => $receipt->id,
                'book_id'                => $item['book_id'],
                'quantity_received'      => $item['quantity'],
                'quantity_sold'          => 0,
                'quantity_returned'      => 0,
                'cost_price'             => $item['cost_price'],
                'selling_price'          => $item['selling_price'],
            ]);

            $lotService = app(\App\Services\Stock\StockLotService::class);
            $inventory = $lotService->ensureAggregate(
                (int) $validated['branch_id'],
                (int) $item['book_id']
            );
            $lotService->setSellPrices($inventory, [
                'price_toman' => $item['price_toman']
                    ?? ($validated['currency'] === 'toman' ? $item['selling_price'] : $inventory->price_toman),
                'price_dinar' => $item['price_dinar']
                    ?? ($validated['currency'] === 'dinar' ? $item['selling_price'] : $inventory->price_dinar),
            ]);

            $branch = \App\Models\Branch::find($validated['branch_id']);
            $book = \App\Models\Book::find($item['book_id']);
            $lotService->createIntakeLot([
                'book_id' => $item['book_id'],
                'branch_id' => $validated['branch_id'],
                'consignment_receipt_item_id' => $receiptItem->id,
                'supplier_id' => $validated['supplier_id'],
                'supplier_account_id' => $validated['supplier_account_id'],
                'ownership_type' => 'consignment',
                'currency' => $validated['currency'],
                'unit_cost' => $item['cost_price'],
                'quantity' => $item['quantity'],
                'origin' => $lotService->resolveOrigin($branch, (bool) $book?->iraq_only),
            ], $receipt);

            StockMovementLogger::log(
                (int) $validated['branch_id'],
                (int) $item['book_id'],
                'in',
                (int) $item['quantity'],
                'received_from_supplier',
                $request->user()->name,
                "ورود امانی — رسید {$receipt->receipt_number}",
            );
        }

        ActivityLogger::record(
            'consignment',
            'created',
            "رسید امانی {$receipt->receipt_number}",
            $receipt,
            [
                'receipt_number' => $receipt->receipt_number,
                'supplier_id' => $receipt->supplier_id,
                'supplier_account_id' => $receipt->supplier_account_id,
                'total_value' => $receipt->total_value,
                'currency' => $receipt->currency,
                'items_count' => count($validated['items']),
            ],
            (int) $validated['branch_id'],
        );

        return response()->json($receipt->load(['items.book', 'supplier', 'branch']), 201);
        });
    }

    public function show(Request $request, ConsignmentReceipt $consignmentReceipt)
    {
        $this->assertCanAccessReceipt($request->user(), $consignmentReceipt);

        $consignmentReceipt->recalculateFromItems();

        $receipt = $consignmentReceipt->fresh()->load(['items.book', 'supplier', 'branch', 'user']);
        foreach ($this->receiptFinancialProfile($receipt) as $key => $value) {
            $receipt->setAttribute($key, $value);
        }

        return response()->json($receipt);
    }

    /**
     * Presentation values for a receipt. Receiving and transferring stock never
     * creates supplier debt; only immutable sale/gift allocations do.
     *
     * @return array<string, int|string>
     */
    private function receiptFinancialProfile(ConsignmentReceipt $receipt): array
    {
        $receipt->loadMissing('items');
        $snapshot = app(SnapshotPayable::class);
        $generated = $snapshot->receiptGenerated($receipt);
        $settled = $snapshot->receiptEffectiveSettled($receipt);
        $outstanding = $snapshot->receiptOutstanding($receipt);

        $status = match (true) {
            Money::isZero($generated) => 'not_due',
            Money::cmp($outstanding, '0') > 0 && Money::isZero($settled) => 'unsettled',
            Money::cmp($outstanding, '0') > 0 => 'partially_settled',
            default => 'settled',
        };

        $received = (int) $receipt->items->sum('quantity_received');
        $sold = (int) $receipt->items->sum('quantity_sold');
        $returned = (int) $receipt->items->sum('quantity_returned');

        return [
            'payable_status' => $status,
            'inventory_value' => Money::of($receipt->total_value),
            'payable_generated' => $generated,
            'payable_settled' => $settled,
            'payable_outstanding' => $outstanding,
            'quantity_received' => $received,
            'quantity_sold' => $sold,
            'quantity_returned' => $returned,
            'quantity_in_stock' => max(0, $received - $sold - $returned),
        ];
    }

    /** @return array<string, string> */
    private function emptyReceiptMoneySummary(): array
    {
        return [
            'inventory_value' => '0.00',
            'payable_generated' => '0.00',
            'payable_settled' => '0.00',
            'payable_outstanding' => '0.00',
        ];
    }

    /** Manually close a receipt that has no remaining items / nothing left to settle. */
    public function close(Request $request, ConsignmentReceipt $consignmentReceipt)
    {
        $this->assertCanAccessReceipt($request->user(), $consignmentReceipt);
        BranchAccess::assertCanMutateInBranch($request->user(), (int) $consignmentReceipt->branch_id);

        $consignmentReceipt->load('items');
        $soldOutstanding = (float) app(SupplierPayable::class)->outstanding($consignmentReceipt);

        if ($consignmentReceipt->items->isNotEmpty() && $soldOutstanding > 0) {
            return response()->json([
                'message' => 'این رسید هنوز مانده تسویه بر اساس فروش دارد. از صفحه تسویه حساب استفاده کنید.',
            ], 422);
        }

        $consignmentReceipt->forceFill([
            'status'         => 'settled',
            'settled_amount' => $consignmentReceipt->items->isEmpty()
                ? 0
                : (float) app(SupplierPayable::class)->publisherOwed($consignmentReceipt),
            'total_value'    => $consignmentReceipt->items->isEmpty()
                ? 0
                : (float) $consignmentReceipt->items->sum(fn ($i) => $i->quantity_received * $i->cost_price),
        ])->save();

        ActivityLogger::record(
            'consignment',
            'closed',
            "بستن رسید امانی {$consignmentReceipt->receipt_number}",
            $consignmentReceipt,
            [
                'receipt_number' => $consignmentReceipt->receipt_number,
                'supplier_id' => $consignmentReceipt->supplier_id,
            ],
            (int) $consignmentReceipt->branch_id,
        );

        return response()->json($consignmentReceipt->fresh()->load(['supplier', 'branch'])->loadCount('items'));
    }

    /** Sold-based outstanding balance per supplier account (operational) or aggregate report. */
    public function unsettledBySupplier(Request $request)
    {
        $request->validate([
            'period_start' => 'nullable|date|required_with:period_end',
            'period_end' => 'nullable|date|after_or_equal:period_start|required_with:period_start',
        ]);

        $periodStart = $request->filled('period_start') ? (string) $request->period_start : null;
        $periodEnd = $request->filled('period_end') ? (string) $request->period_end : null;
        $aggregate = $request->boolean('aggregate');

        if ($aggregate) {
            BranchAccess::assertCanAggregateSupplierAccounts($request->user());
            $query = ConsignmentReceipt::with(['items', 'supplierAccount', 'supplier'])
                ->whereIn('status', ['unsettled', 'partially_settled']);
            if ($request->filled('branch_id')) {
                $query->where('branch_id', (int) $request->branch_id);
            }
            $receipts = $query->get();

            return response()->json([
                'aggregate' => true,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'rows' => $this->buildOutstandingRows($receipts, $periodStart, $periodEnd),
            ]);
        }

        if (!$periodStart || !$periodEnd) {
            return response()->json([
                'message' => 'بازه تسویه برای بدهی عملیاتی الزامی است',
                'error' => 'period_required',
            ], 422);
        }

        $branchId = BranchAccess::resolveOperationalBranchId(
            $request->user(),
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );

        $receipts = ConsignmentReceipt::with(['items', 'supplierAccount', 'supplier'])
            ->whereIn('status', ['unsettled', 'partially_settled'])
            ->where('branch_id', $branchId)
            ->get();

        return response()->json([
            'branch_id' => $branchId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'rows' => $this->buildOutstandingRows($receipts, $periodStart, $periodEnd),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ConsignmentReceipt>  $receipts
     * @return list<array<string, mixed>>
     */
    private function buildOutstandingRows($receipts, ?string $periodStart = null, ?string $periodEnd = null): array
    {
        $period = app(PeriodSettlement::class);
        $usePeriod = $periodStart && $periodEnd;
        $bucket = [];

        foreach ($receipts as $receipt) {
            if (!$receipt->supplier_account_id) {
                throw new DomainException(
                    'رسید امانی بدون supplier_account_id — داده ناسازگار است',
                    422,
                    ['error' => 'supplier_account_integrity', 'receipt_id' => $receipt->id]
                );
            }

            if ($usePeriod) {
                continue;
            }

            $balance = app(SupplierPayable::class)->outstanding($receipt);
            if (Money::cmp($balance, '0') <= 0) {
                continue;
            }

            $currency = $receipt->currency;
            $key = $receipt->supplier_account_id.':'.$receipt->branch_id.':'.$currency;
            if (!isset($bucket[$key])) {
                $bucket[$key] = [
                    'supplier_account_id' => (int) $receipt->supplier_account_id,
                    'supplier_id' => (int) $receipt->supplier_id,
                    'branch_id' => (int) $receipt->branch_id,
                    'display_name' => $receipt->supplierAccount?->display_name
                        ?? $receipt->supplier?->name
                        ?? '#'.$receipt->supplier_account_id,
                    'currency' => $currency,
                    'balance' => '0.00',
                    'expected_total' => '0.00',
                ];
            }
            $bucket[$key]['balance'] = Money::add($bucket[$key]['balance'], $balance);
            $bucket[$key]['expected_total'] = $bucket[$key]['balance'];
        }

        if ($usePeriod) {
            $accounts = [];
            foreach ($receipts as $receipt) {
                if (!$receipt->supplier_account_id) {
                    throw new DomainException(
                        'رسید امانی بدون supplier_account_id — داده ناسازگار است',
                        422,
                        ['error' => 'supplier_account_integrity', 'receipt_id' => $receipt->id]
                    );
                }
                $key = $receipt->supplier_account_id.':'.$receipt->branch_id.':'.$receipt->currency;
                $accounts[$key] = $receipt;
            }
            foreach ($accounts as $receipt) {
                $preview = $period->preview(
                    (int) $receipt->supplier_id,
                    (string) $receipt->currency,
                    $periodStart,
                    $periodEnd,
                    (int) $receipt->branch_id,
                    (int) $receipt->supplier_account_id
                );
                $balance = $preview['total_payable'];
                if (Money::cmp($balance, '0') <= 0) {
                    continue;
                }
                $key = $receipt->supplier_account_id.':'.$receipt->branch_id.':'.$receipt->currency;
                $bucket[$key] = [
                    'supplier_account_id' => (int) $receipt->supplier_account_id,
                    'supplier_id' => (int) $receipt->supplier_id,
                    'branch_id' => (int) $receipt->branch_id,
                    'display_name' => $receipt->supplierAccount?->display_name
                        ?? $receipt->supplier?->name
                        ?? '#'.$receipt->supplier_account_id,
                    'currency' => $receipt->currency,
                    'balance' => $balance,
                    'expected_total' => $balance,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ];
            }
        }

        return array_values($bucket);
    }

    /** Settle a supplier */
    public function settle(Request $request)
    {
        $validated = $request->validate([
            'supplier_account_id' => 'nullable|exists:supplier_accounts,id',
            'supplier_id'    => 'required_without:supplier_account_id|nullable|exists:suppliers,id',
            'branch_id'      => 'nullable|exists:branches,id',
            'period_type'    => 'required|in:monthly,quarterly,custom',
            'period_start'   => 'required|date',
            'period_end'     => 'required|date|after_or_equal:period_start',
            'amount'         => 'required|numeric|min:0.01',
            'currency'       => 'required|in:toman,dinar',
            'payment_method' => 'required|in:cash,bank_transfer,check',
            'notes'          => 'nullable|string',
            'check_number'   => 'required_if:payment_method,check|nullable|string',
            'bank_name'      => 'nullable|string',
            'financial_account_id' => 'nullable|exists:financial_accounts,id',
        ]);

        if ($request->boolean('aggregate')) {
            return response()->json(['message' => 'حالت تجمیعی برای ثبت مجاز نیست', 'error' => 'aggregate_not_allowed'], 422);
        }

        $settlement = app(SettlementRecorder::class)->create($request->user(), $validated);

        ActivityLogger::record(
            'settlements',
            'settled',
            "تسویه {$settlement->settlement_number} — {$settlement->amount} {$settlement->currency}",
            $settlement,
            [
                'settlement_number' => $settlement->settlement_number,
                'supplier_id' => $settlement->supplier_id,
                'amount' => $settlement->amount,
                'currency' => $settlement->currency,
                'payment_method' => $settlement->payment_method,
            ],
            $settlement->branch_id ? (int) $settlement->branch_id : null,
        );

        return response()->json($settlement, 201);
    }

    public function settlements(Request $request)
    {
        $query = Settlement::with(['supplier', 'branch', 'user']);

        if ($request->boolean('aggregate')) {
            BranchAccess::assertCanAggregateSupplierAccounts($request->user());
        } else {
            $branchId = BranchAccess::resolveOperationalBranchId(
                $request->user(),
                $request->filled('branch_id') ? (int) $request->branch_id : null
            );
            $query->where('branch_id', $branchId);
        }

        if ($request->filled('supplier_account_id')) {
            $query->where('supplier_account_id', (int) $request->supplier_account_id);
        }
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('branch_id') && $request->boolean('aggregate')) {
            $query->where('branch_id', (int) $request->branch_id);
        }

        return response()->json($query->latest()->paginate(20));
    }

    /** Preview payable using the same SupplierPayable math as settle. */
    public function settlementPreview(Request $request)
    {
        $validated = $request->validate([
            'supplier_account_id' => 'nullable|exists:supplier_accounts,id',
            'supplier_id'  => 'required_without:supplier_account_id|nullable|exists:suppliers,id',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'currency'     => 'nullable|in:toman,dinar',
            'branch_id'    => 'nullable|exists:branches,id',
        ]);

        if ($request->boolean('aggregate')) {
            return response()->json(['message' => 'حالت تجمیعی برای پیش‌نمایش عملیاتی مجاز نیست', 'error' => 'aggregate_not_allowed'], 422);
        }

        $scope = app(\App\Services\Suppliers\SupplierSettlementScope::class)->resolve($request->user(), $validated);
        $currency = $validated['currency'] ?? 'toman';
        $preview = app(\App\Services\Settlement\PeriodSettlement::class)->preview(
            $scope['supplier_id'],
            $currency,
            $validated['period_start'],
            $validated['period_end'],
            $scope['branch_id'],
            $scope['supplier_account_id']
        );

        return response()->json([
            'items' => $preview['lines'],
            'commission_rate' => $preview['commission_rate'],
            'total_payable' => $preview['total_payable'],
            'period_start' => $preview['period_start'],
            'period_end' => $preview['period_end'],
            'currency' => $preview['currency'],
            'supplier_account_id' => $scope['supplier_account_id'],
            'branch_id' => $scope['branch_id'],
        ]);
    }

    /** Settle multiple suppliers at once (atomic). */
    public function settleBulk(Request $request)
    {
        $validated = $request->validate([
            'settlements'                    => 'required|array|min:1',
            'settlements.*.supplier_account_id' => 'nullable|exists:supplier_accounts,id',
            'settlements.*.supplier_id'      => 'required_without:settlements.*.supplier_account_id|nullable|exists:suppliers,id',
            'settlements.*.amount'           => 'required|numeric|min:0.01',
            'settlements.*.expected_total'   => 'nullable|numeric|min:0.01',
            'settlements.*.currency'         => 'required|in:toman,dinar',
            'settlements.*.branch_id'        => 'nullable|exists:branches,id',
            'settlements.*.financial_account_id' => 'nullable|exists:financial_accounts,id',
            'settlements.*.check_number'     => 'nullable|string',
            'settlements.*.bank_name'        => 'nullable|string',
            'period_type'                    => 'required|in:monthly,quarterly,custom',
            'period_start'                   => 'required|date',
            'period_end'                     => 'required|date|after_or_equal:period_start',
            'payment_method'                 => 'required|in:cash,bank_transfer,check',
            'notes'                          => 'nullable|string',
        ]);

        $header = [
            'period_type' => $validated['period_type'],
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'payment_method' => $validated['payment_method'],
            'notes' => $validated['notes'] ?? null,
        ];

        $created = app(SettlementRecorder::class)->createMany(
            $request->user(),
            $header,
            $validated['settlements']
        );

        ActivityLogger::record(
            'settlements',
            'settled',
            'تسویه گروهی — '.count($created).' تأمین‌کننده',
            null,
            [
                'count' => count($created),
                'period_start' => $validated['period_start'],
                'period_end' => $validated['period_end'],
                'payment_method' => $validated['payment_method'],
            ],
        );

        return response()->json(['settlements' => $created], 201);
    }

    public function updateSettlementCheck(Request $request, Settlement $settlement)
    {
        $validated = $request->validate([
            'check_status' => 'required|in:pending,cleared,bounced,cancelled',
            'financial_account_id' => 'nullable|exists:financial_accounts,id',
        ]);

        $updated = app(SupplierCheckTransition::class)->apply(
            $settlement,
            (string) $validated['check_status'],
            $request->user(),
            $validated['financial_account_id'] ?? null
        );

        return response()->json($updated);
    }
}
