<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Check;
use App\Models\Customer;
use App\Models\CustomerReturnItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Inventory;
use App\Support\ActivityLogger;
use App\Support\StockMovementLogger;
use App\Services\Stock\StockLotService;
use App\Services\Ledger\FinancePostingGateway;
use App\Services\Ledger\IncomingCheckTransition;
use App\Services\Receivables\InvoiceBalance;
use App\Support\Authorization\BranchAccess;
use App\Models\SaleLotAllocation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Invoice::with(['items.book', 'branch', 'user']);

        $ids = BranchAccess::visibleBranchIds($user);
        if ($ids !== null) {
            $query->whereIn('branch_id', $ids ?: [0]);
        } elseif ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        if ($request->has('date_from')) {
            $query->whereDate('sold_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('sold_at', '<=', $request->date_to);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->customer_id);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhereHas('items.book', function ($bq) use ($search) {
                      $bq->where('title', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%");
                  });
            });
        }

        $page = $query->latest('sold_at')->paginate(20);
        $page->getCollection()->transform(function (Invoice $invoice) {
            $invoice->setRelation(
                'items',
                $invoice->items->map(function ($item) {
                    $returned = (int) \App\Models\CustomerReturnItem::query()
                        ->where('invoice_item_id', $item->id)
                        ->sum('quantity');
                    $item->setAttribute('quantity_returned', $returned);
                    $item->setAttribute('returnable_quantity', max(0, (int) $item->quantity - $returned));
                    return $item;
                })
            );
            return $invoice;
        });

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'        => 'nullable|exists:branches,id',
            'payment_method'   => 'required|in:cash,card,check,credit',
            'currency'         => 'required|in:toman,dinar',
            'customer_name'    => 'nullable|string|max:255',
            'customer_phone'   => 'nullable|string|max:30',
            'notes'            => 'nullable|string',
            'due_date'         => 'required_if:payment_method,check,credit|nullable|date',
            'type'             => 'in:sale,gift',
            'items'            => 'required|array|min:1',
            'items.*.book_id'      => 'required|exists:books,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.unit_price'   => 'nullable|numeric|min:0',
            'items.*.actual_price' => 'nullable|numeric|min:0',
            'items.*.discount'     => 'nullable|numeric|min:0',
            'customer_id'      => 'required_if:payment_method,credit|nullable|exists:customers,id',
            'items.*.override_reason' => 'nullable|string|max:255',
            'check_number'     => 'required_if:payment_method,check|nullable|string',
            'bank_name'        => 'nullable|string',
            'payer_name'       => 'required_if:payment_method,check|nullable|string',
            'financial_account_id' => 'nullable|exists:financial_accounts,id',
            'payer_phone'      => 'nullable|string',
        ]);

        $user = $request->user();
        BranchAccess::assertCanMutateFinance($user);
        $branchId = BranchAccess::resolveActorBranchId(
            $user,
            isset($validated['branch_id']) ? (int) $validated['branch_id'] : null
        );

        $stockNeeded = [];
        foreach ($validated['items'] as $item) {
            $bookId = (int) $item['book_id'];
            $stockNeeded[$bookId] = ($stockNeeded[$bookId] ?? 0) + (int) $item['quantity'];
        }

        return DB::transaction(function () use ($request, $validated, $user, $branchId, $stockNeeded) {
            $linkedCustomer = $this->resolveSaleCustomer(
                isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
                $branchId,
                $validated['payment_method'] === 'credit'
            );
            $customerName = $validated['customer_name'] ?? $linkedCustomer?->name;
            $customerPhone = $validated['customer_phone'] ?? $linkedCustomer?->phone;
            $inventories = [];
            foreach ($stockNeeded as $bookId => $qty) {
                $inventory = Inventory::where('branch_id', $branchId)
                    ->where('book_id', $bookId)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory || $inventory->quantity < $qty) {
                    throw new DomainException('موجودی کافی برای یکی از کتاب‌ها وجود ندارد', 422, [
                        'book_id' => $bookId,
                    ]);
                }
                $inventories[$bookId] = $inventory;
            }

            $prepared = [];
            foreach ($validated['items'] as $item) {
                $bookId = (int) $item['book_id'];
                $inventory = $inventories[$bookId];

                $listPrice = $validated['currency'] === 'dinar'
                    ? Money::of($inventory->price_dinar ?? 0)
                    : Money::of($inventory->price_toman ?? 0);

                $actualPrice = isset($item['actual_price']) ? Money::of($item['actual_price']) : $listPrice;
                $discount = Money::of($item['discount'] ?? 0);

                if (Money::isNegative($actualPrice) || Money::isNegative($discount)) {
                    throw new DomainException('مبالغ منفی مجاز نیست');
                }
                if (Money::cmp($discount, $actualPrice) > 0) {
                    throw new DomainException('تخفیف نمی‌تواند بیشتر از قیمت فروش باشد', 422, [
                        'book_id' => $item['book_id'],
                    ]);
                }

                $overrideBy = null;
                $overrideReason = $item['override_reason'] ?? null;
                if (Money::cmp($actualPrice, $listPrice) < 0) {
                    if (Money::isZero($discount)) {
                        $discount = Money::sub($listPrice, $actualPrice);
                    }
                    $overrideBy = $user->id;
                    if (!$overrideReason) {
                        $overrideReason = 'markdown';
                    }
                }
                if (Money::isZero($actualPrice) && ($validated['type'] ?? 'sale') !== 'gift') {
                    if (!BranchAccess::canOverridePrice($user) || !$overrideReason) {
                        throw new DomainException('فروش با قیمت صفر فقط با مجوز و دلیل مجاز است', 422, [
                            'book_id' => $item['book_id'],
                        ]);
                    }
                    $overrideBy = $user->id;
                }
                if (Money::cmp($actualPrice, $listPrice) > 0) {
                    if (!BranchAccess::canOverridePrice($user)) {
                        throw new DomainException('فروش بالاتر از قیمت ثبت‌شده مجاز نیست', 403, [
                            'book_id' => $item['book_id'],
                        ]);
                    }
                    if (!$overrideReason) {
                        throw new DomainException('دلیل افزایش قیمت الزامی است', 422, [
                            'book_id' => $item['book_id'],
                        ]);
                    }
                    $overrideBy = $user->id;
                }

                $prepared[] = [
                    'book_id' => $item['book_id'],
                    'quantity' => $item['quantity'],
                    'list_price' => $listPrice,
                    'unit_price' => $listPrice,
                    'actual_price' => $actualPrice,
                    'discount' => $discount,
                    'override_by' => $overrideBy,
                    'override_reason' => $overrideBy ? $overrideReason : null,
                    'inventory' => $inventory,
                ];
            }

            $grossSubtotal = '0.00';
            $discountTotal = '0.00';
            foreach ($prepared as $item) {
                $grossSubtotal = Money::add($grossSubtotal, Money::mul($item['actual_price'], $item['quantity']));
                $discountTotal = Money::add($discountTotal, Money::mul($item['discount'], $item['quantity']));
            }
            $netTotal = Money::max('0', Money::sub($grossSubtotal, $discountTotal));

            $invoice = Invoice::create([
                'branch_id'       => $branchId,
                'customer_id'     => $linkedCustomer?->id,
                'user_id'         => $user->id,
                'invoice_number'  => 'INV-' . strtoupper(Str::random(8)),
                'payment_method'  => $validated['payment_method'],
                'payment_status'  => in_array($validated['payment_method'], ['check', 'credit']) ? 'pending' : 'paid',
                'currency'        => $validated['currency'],
                'subtotal'        => $grossSubtotal,
                'discount_amount' => $discountTotal,
                'total'           => $netTotal,
                'customer_name'   => $customerName,
                'customer_phone'  => $customerPhone,
                'notes'           => $validated['notes'] ?? null,
                'due_date'        => $validated['due_date'] ?? null,
                'type'            => $validated['type'] ?? 'sale',
                'sold_at'         => now(),
            ]);

            $lotService = app(StockLotService::class);

            foreach ($prepared as $item) {
                $invoiceItem = InvoiceItem::create([
                    'invoice_id'      => $invoice->id,
                    'book_id'         => $item['book_id'],
                    'quantity'        => $item['quantity'],
                    'list_price'      => $item['list_price'],
                    'unit_price'      => $item['unit_price'],
                    'actual_price'    => $item['actual_price'],
                    'discount'        => $item['discount'],
                    'override_by'     => $item['override_by'],
                    'override_reason' => $item['override_reason'],
                ]);

                $lotService->allocateSale($invoiceItem, $branchId, (int) $item['quantity'], $validated['currency']);

                StockMovementLogger::log(
                    $branchId,
                    (int) $item['book_id'],
                    'out',
                    (int) $item['quantity'],
                    'other',
                    $user->name,
                    "فروش — فاکتور {$invoice->invoice_number}",
                );
            }

            if ($validated['payment_method'] === 'check') {
                Check::create([
                    'invoice_id'   => $invoice->id,
                    'branch_id'    => $branchId,
                    'check_number' => $validated['check_number'],
                    'bank_name'    => $validated['bank_name'] ?? null,
                    'payer_name'   => $validated['payer_name'] ?? $customerName ?? 'نامشخص',
                    'payer_phone'  => $validated['payer_phone'] ?? $customerPhone ?? null,
                    'amount'       => $netTotal,
                    'currency'     => $validated['currency'],
                    'due_date'     => $validated['due_date'],
                ]);
            }

            ActivityLogger::record(
                'sales',
                'sold',
                "فروش فاکتور {$invoice->invoice_number} — {$netTotal} {$validated['currency']}",
                $invoice,
                [
                    'invoice_number' => $invoice->invoice_number,
                    'payment_method' => $validated['payment_method'],
                    'total' => $netTotal,
                    'currency' => $validated['currency'],
                    'items_count' => count($prepared),
                ],
                $branchId,
            );

            $cogs = '0.00';
            foreach (SaleLotAllocation::whereIn('invoice_item_id', $invoice->items()->pluck('id'))->get() as $a) {
                $cogs = Money::add($cogs, Money::mul($a->unit_cost, (int) $a->quantity - (int) $a->quantity_returned));
            }

            app(FinancePostingGateway::class)->sale(
                $invoice,
                $netTotal,
                $cogs,
                $validated['currency'],
                $branchId,
                $validated['payment_method'],
                $validated['financial_account_id'] ?? null
            );

            app(InvoiceBalance::class)->refresh($invoice);

            return response()->json($invoice->fresh()->load(['items.book', 'branch', 'user']), 201);
        });
    }

    public function show(Invoice $invoice)
    {
        BranchAccess::assertBranchAllowed(request()->user(), (int) $invoice->branch_id);

        return response()->json(
            $invoice->load(['items.book:id,title,author,isbn', 'branch:id,name', 'user:id,name', 'check'])
        );
    }

    public function checks(Request $request)
    {
        $user = $request->user();

        $query = Check::query()
            ->select([
                'id', 'invoice_id', 'branch_id', 'check_number', 'bank_name',
                'payer_name', 'payer_phone', 'amount', 'currency', 'due_date', 'status',
            ])
            ->with([
                'invoice:id,invoice_number',
                'branch:id,name',
            ]);

        $ids = BranchAccess::visibleBranchIds($user);
        if ($ids !== null) {
            $query->whereIn('branch_id', $ids ?: [0]);
        } elseif ($request->filled('branch_id')) {
            BranchAccess::assertBranchAllowed($user, (int) $request->branch_id);
            $query->where('branch_id', $request->branch_id);
        }

        // Single list load — filters applied on the client for snappy UX
        $checks = $query->orderByDesc('due_date')->orderByDesc('id')->get();

        $today = now()->toDateString();
        $week = now()->addDays(7)->toDateString();

        $pending = $checks->where('status', 'pending');
        $stats = [
            'pending' => $pending->count(),
            'cleared' => $checks->where('status', 'cleared')->count(),
            'bounced' => $checks->where('status', 'bounced')->count(),
            'overdue' => $pending->filter(function ($c) use ($today) {
                $due = $c->due_date ? $c->due_date->toDateString() : null;
                return $due && $due < $today;
            })->count(),
            'pending_amount_toman' => (float) $pending->where('currency', 'toman')->sum('amount'),
            'pending_amount_dinar' => (float) $pending->where('currency', 'dinar')->sum('amount'),
            'due_soon' => $pending->filter(function ($c) use ($today, $week) {
                $due = $c->due_date ? $c->due_date->toDateString() : null;
                return $due && $due >= $today && $due <= $week;
            })->count(),
        ];

        return response()->json([
            'checks' => $checks,
            'stats'  => $stats,
        ]);
    }

    public function updateCheck(Request $request, Check $check)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,cleared,bounced',
            'financial_account_id' => 'nullable|exists:financial_accounts,id',
        ]);

        $updated = app(IncomingCheckTransition::class)->apply(
            $check,
            (string) $validated['status'],
            $request->user(),
            $validated['financial_account_id'] ?? null
        );

        ActivityLogger::record(
            'sales',
            'status_changed',
            "وضعیت چک {$updated->check_number} → {$updated->status}",
            $updated,
            [
                'check_number' => $updated->check_number,
                'status' => $updated->status,
                'invoice_id' => $updated->invoice_id,
            ],
            (int) $updated->branch_id,
        );

        return response()->json($updated);
    }

    public function credits(Request $request)
    {
        $user = $request->user();

        // Sync overdue: pending credits past due date
        Invoice::query()
            ->where('payment_method', 'credit')
            ->whereIn('payment_status', ['pending', 'partially_paid'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['payment_status' => 'overdue']);

        $query = Invoice::with(['branch', 'user', 'items.book'])
            ->where('payment_method', 'credit');

        $ids = BranchAccess::visibleBranchIds($user);
        if ($ids !== null) {
            $query->whereIn('branch_id', $ids ?: [0]);
        } elseif ($request->filled('branch_id')) {
            BranchAccess::assertBranchAllowed($user, (int) $request->branch_id);
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('payment_status') && $request->payment_status !== 'all') {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $dueSoonQuery = Invoice::with(['branch'])
            ->where('payment_method', 'credit')
            ->whereIn('payment_status', ['pending', 'overdue', 'partially_paid'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()]);

        $ids = BranchAccess::visibleBranchIds($user);
        if ($ids !== null) {
            $dueSoonQuery->whereIn('branch_id', $ids ?: [0]);
        }

        return response()->json([
            'credits'  => $query->latest('due_date')->get(),
            'due_soon' => $dueSoonQuery->orderBy('due_date')->get(),
        ]);
    }

    public function updateCredit(Request $request, Invoice $invoice)
    {
        if ($invoice->payment_method !== 'credit') {
            return response()->json(['message' => 'این فاکتور نسیه نیست'], 422);
        }

        BranchAccess::assertCanMutateInBranch($request->user(), (int) $invoice->branch_id);

        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid,overdue,partially_paid',
        ]);

        app(FinancePostingGateway::class)->assertLegacyCreditStatusAllowed($validated['payment_status']);

        $invoice->update(['payment_status' => $validated['payment_status']]);

        ActivityLogger::record(
            'sales',
            'status_changed',
            "وضعیت نسیه {$invoice->invoice_number} → {$validated['payment_status']}",
            $invoice,
            [
                'invoice_number' => $invoice->invoice_number,
                'payment_status' => $validated['payment_status'],
            ],
            (int) $invoice->branch_id,
        );

        return response()->json($invoice->load(['branch', 'user', 'items.book']));
    }

    private function resolveSaleCustomer(?int $customerId, int $branchId, bool $required): ?Customer
    {
        if (!$customerId) {
            if ($required) {
                throw new DomainException('فروش نسیه نیازمند مشتری ثبت‌شده است', 422);
            }

            return null;
        }

        $customer = Customer::query()->lockForUpdate()->find($customerId);
        if (!$customer) {
            throw new DomainException('مشتری یافت نشد', 422);
        }
        if ($customer->archived_at) {
            throw new DomainException('مشتری غیرفعال است', 422);
        }
        if ($customer->branch_id !== null && (int) $customer->branch_id !== $branchId) {
            throw new DomainException('این مشتری در شعبه فاکتور قابل استفاده نیست', 422);
        }

        return $customer;
    }
}
