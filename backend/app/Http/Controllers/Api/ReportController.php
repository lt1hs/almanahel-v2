<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Check;
use App\Models\Book;
use App\Models\Transfer;
use App\Models\AppSetting;
use App\Services\Reports\LedgerReportService;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Support\IntakePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ReportController extends Controller
{
    public function allBranchBalance(Request $request)
    {
        BranchAccess::assertCanViewAllReports($request->user());
        $period = app(LedgerReportService::class)->period(
            $request->input('date_from'),
            $request->input('date_to')
        );

        return response()->json(app(LedgerReportService::class)->allBranchPnls($period['from'], $period['to']));
    }

    public function dashboardStats(Request $request)
    {
        $user = $request->user();
        $alertBranches = BranchAccess::alertBranchIds($user);
        $canFinance = BranchAccess::canViewFinancialReports($user);
        $canAllReports = BranchAccess::canViewAllReports($user);

        $inventoryQuery = Inventory::query();
        if ($alertBranches !== null) {
            $inventoryQuery->whereIn('branch_id', $alertBranches ?: [0]);
        }

        $totalTitles = $canAllReports
            ? Book::count()
            : (int) (clone $inventoryQuery)->distinct()->count('book_id');

        $totalStock = (int) (clone $inventoryQuery)->sum('quantity');

        $financials = !$canFinance
            ? [
                'today_sales_toman' => '0.00',
                'today_sales_dinar' => '0.00',
                'today_gross_sales_toman' => '0.00',
                'today_gross_sales_dinar' => '0.00',
                'today_returns_toman' => '0.00',
                'today_returns_dinar' => '0.00',
                'today_invoice_count' => 0,
                'inventory_value_toman' => '0.00',
                'inventory_value_dinar' => '0.00',
                'inventory_value_basis' => 'owned_inventory_at_cost',
            ]
            : app(LedgerReportService::class)->dashboardFinancials(
                $user->role === 'branch_manager' ? (int) $user->branch_id : null,
                now()
            );

        $totalSuppliers = $canAllReports ? \App\Models\Supplier::count() : 0;
        $totalBranches = $canAllReports
            ? Branch::where('status', 'active')->count()
            : 0;
        $pendingChecks = 0;
        if ($canFinance) {
            $pendingQuery = Check::where('status', 'pending')
                ->where('due_date', '<=', now()->addDays(7));
            if ($alertBranches !== null) {
                $pendingQuery->whereIn('branch_id', $alertBranches ?: [0]);
            }
            $pendingChecks = $pendingQuery->count();
        }

        $globalThreshold = (int) AppSetting::get('almanahel.low_stock_threshold', config('almanahel.low_stock_threshold', 5));
        $lowStockCount = Inventory::query()
            ->join('books', 'inventories.book_id', '=', 'books.id')
            ->when($alertBranches !== null, fn ($q) => $q->whereIn('inventories.branch_id', $alertBranches ?: [0]))
            ->where('inventories.quantity', '>', 0)
            ->whereRaw(
                'inventories.quantity <= COALESCE(books.low_stock_threshold, ?)',
                [$globalThreshold]
            )
            ->count();

        $outOfStockCount = $canAllReports
            ? Book::whereDoesntHave('inventories', fn ($q) => $q->where('quantity', '>', 0))->count()
            : 0;

        return response()->json(array_merge([
            'total_titles'           => $totalTitles,
            'total_stock'            => $totalStock,
            'total_books'            => $totalTitles,
            'total_suppliers'        => $totalSuppliers,
            'total_branches'         => $totalBranches,
            'low_stock_count'        => $lowStockCount,
            'out_of_stock_count'     => $outOfStockCount,
            'pending_checks'         => $pendingChecks,
        ], $financials));
    }

    public function monthlyTrends(Request $request)
    {
        $branchId = BranchAccess::resolveReportBranchId($request->user(), $request->input('branch_id'));
        $currency = app(LedgerReportService::class)->currency($request->input('currency'));
        $months = min(12, max(3, (int) ($request->months ?? 6)));

        return response()->json(app(LedgerReportService::class)->monthlyTrends($branchId, $currency, $months));
    }

    public function topBooks(Request $request)
    {
        BranchAccess::assertCanViewFinancialReports($request->user());
        $period = app(LedgerReportService::class)->period(
            $request->input('date_from'),
            $request->input('date_to')
        );
        $branchId = BranchAccess::resolveReportBranchId($request->user(), $request->input('branch_id'));
        $currency = $request->filled('currency')
            ? app(LedgerReportService::class)->currency($request->input('currency'))
            : null;

        return response()->json(app(LedgerReportService::class)->topBooks($branchId, $period['from'], $period['to'], $currency));
    }

    public function notifications(Request $request)
    {
        $user = $request->user();
        $globalThreshold = (int) AppSetting::get('almanahel.low_stock_threshold', config('almanahel.low_stock_threshold', 5));
        $alertBranches = \App\Support\Authorization\BranchAccess::alertBranchIds($user);

        $lowStockQuery = Inventory::query()
            ->with(['book:id,title,low_stock_threshold', 'branch:id,name'])
            ->join('books', 'inventories.book_id', '=', 'books.id')
            ->where('inventories.quantity', '>', 0)
            ->whereRaw(
                'inventories.quantity <= COALESCE(books.low_stock_threshold, ?)',
                [$globalThreshold]
            )
            ->select('inventories.*');
        if ($alertBranches !== null) {
            $lowStockQuery->whereIn('inventories.branch_id', $alertBranches ?: [0]);
        }

        $lowStock = $lowStockQuery
            ->limit(50)
            ->get()
            ->map(fn ($inv) => [
                'type'    => 'low_stock',
                'message' => "موجودی \"{$inv->book?->title}\" در شعبه \"{$inv->branch?->name}\" به {$inv->quantity} عدد رسید",
                'data'    => [
                    'inventory_id' => $inv->id,
                    'book_id'      => $inv->book_id,
                    'book_title'   => $inv->book?->title,
                    'branch_id'    => $inv->branch_id,
                    'branch_name'  => $inv->branch?->name,
                    'quantity'     => $inv->quantity,
                    'threshold'    => $inv->book?->low_stock_threshold ?? $globalThreshold,
                ],
            ]);

        $dueChecksQuery = Check::with(['branch', 'invoice'])
            ->where('status', 'pending')
            ->where('due_date', '<=', now()->addDays(3));
        if ($alertBranches !== null) {
            $dueChecksQuery->whereIn('branch_id', $alertBranches ?: [0]);
        }

        $dueChecks = $dueChecksQuery
            ->get()
            ->map(fn($chk) => [
                'type'    => 'check_due',
                'message' => "چک شماره {$chk->check_number} از {$chk->payer_name} سررسید می‌شود ({$chk->due_date})",
                'data'    => [
                    'check_id'     => $chk->id,
                    'check_number' => $chk->check_number,
                    'payer_name'   => $chk->payer_name,
                    'due_date'     => $chk->due_date,
                    'amount'       => $chk->amount,
                    'currency'     => $chk->currency,
                    'branch_id'    => $chk->branch_id,
                    'branch_name'  => $chk->branch?->name,
                ],
            ]);

        $dueCreditsQuery = Invoice::with('branch')
            ->where('payment_method', 'credit')
            ->where('payment_status', 'pending')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now()->addDays(3));
        if ($alertBranches !== null) {
            $dueCreditsQuery->whereIn('branch_id', $alertBranches ?: [0]);
        }

        $dueCredits = $dueCreditsQuery
            ->get()
            ->map(fn($inv) => [
                'type'    => 'credit_due',
                'message' => "فاکتور {$inv->invoice_number} از {$inv->customer_name} سررسید پرداخت دارد",
                'data'    => [
                    'invoice_id'     => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'customer_name'  => $inv->customer_name,
                    'due_date'       => $inv->due_date,
                    'total'          => $inv->total,
                    'currency'       => $inv->currency,
                    'branch_id'      => $inv->branch_id,
                    'branch_name'    => $inv->branch?->name,
                ],
            ]);

        $transfers = $this->transferNotifications($user);

        return response()->json([
            'low_stock'    => $lowStock->values(),
            'due_checks'   => $dueChecks,
            'due_credits'  => $dueCredits,
            'transfers'    => $transfers,
            'total'        => $lowStock->count() + $dueChecks->count() + $dueCredits->count() + count($transfers),
        ]);
    }

    public function iraqProfit(Request $request)
    {
        BranchAccess::assertCanViewAllReports($request->user());
        $period = app(LedgerReportService::class)->period(
            $request->input('date_from'),
            $request->input('date_to')
        );
        $currency = app(LedgerReportService::class)->currency($request->input('currency'));

        return response()->json(app(LedgerReportService::class)->iraqProfit($period['from'], $period['to'], $currency));
    }

    public function distributionFromQom(Request $request)
    {
        $qom = IntakePolicy::qomBranch();
        if (!$qom) {
            return response()->json(['from_branch' => null, 'summary' => [], 'transfers' => []]);
        }

        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();

        $transfers = Transfer::with(['toBranch', 'fromBranch', 'user'])
            ->where('from_branch_id', $qom->id)
            ->where('status', 'received')
            ->whereBetween(DB::raw('DATE(updated_at)'), [$dateFrom, $dateTo])
            ->latest('updated_at')
            ->get();

        $byBranch = [];
        $byBook = [];

        foreach ($transfers as $transfer) {
            $toName = $transfer->toBranch?->name ?? '—';
            $toId = $transfer->to_branch_id;

            if (!isset($byBranch[$toId])) {
                $byBranch[$toId] = [
                    'branch_id'   => $toId,
                    'branch_name' => $toName,
                    'total_books' => 0,
                    'transfer_count' => 0,
                ];
            }
            $byBranch[$toId]['transfer_count']++;

            foreach ($transfer->items ?? [] as $item) {
                $qty = (int) ($item['quantity'] ?? 0);
                $bookId = (int) ($item['book_id'] ?? 0);
                $byBranch[$toId]['total_books'] += $qty;

                if ($bookId) {
                    if (!isset($byBook[$bookId])) {
                        $book = Book::find($bookId);
                        $byBook[$bookId] = [
                            'book_id'    => $bookId,
                            'title'      => $book?->title ?? '—',
                            'total_sent' => 0,
                            'to_branches' => [],
                        ];
                    }
                    $byBook[$bookId]['total_sent'] += $qty;
                    if (!isset($byBook[$bookId]['to_branches'][$toId])) {
                        $byBook[$bookId]['to_branches'][$toId] = [
                            'branch_id'   => $toId,
                            'branch_name' => $toName,
                            'quantity'    => 0,
                        ];
                    }
                    $byBook[$bookId]['to_branches'][$toId]['quantity'] += $qty;
                }
            }
        }

        $books = collect($byBook)->map(function ($row) {
            $row['to_branches'] = array_values($row['to_branches']);
            return $row;
        })->values();

        return response()->json([
            'from_branch' => ['id' => $qom->id, 'name' => $qom->name],
            'period'      => ['from' => $dateFrom, 'to' => $dateTo],
            'summary'     => array_values($byBranch),
            'by_book'     => $books,
            'transfers'   => $transfers,
        ]);
    }

    public function settings()
    {
        return response()->json($this->settingsPayload());
    }

    public function updateSettings(Request $request)
    {
        \App\Support\Authorization\BranchAccess::assertCanManageSettings($request->user());

        $validated = $request->validate([
            'low_stock_threshold' => 'sometimes|integer|min:1|max:100',
            'toman_per_1000_dinar' => 'sometimes|numeric|min:1|max:10000000',
            // Legacy alias — same meaning as toman_per_1000_dinar.
            'toman_to_dinar_rate' => 'sometimes|numeric|min:1|max:10000000',
            'consignment_commission_rate' => 'sometimes|numeric|min:0|max:0.5',
            'rate_notes' => 'sometimes|nullable|string|max:500',
        ]);

        if (array_key_exists('low_stock_threshold', $validated)) {
            AppSetting::put('almanahel.low_stock_threshold', (int) $validated['low_stock_threshold']);
        }

        $rateInput = $validated['toman_per_1000_dinar']
            ?? $validated['toman_to_dinar_rate']
            ?? null;
        if ($rateInput !== null) {
            $this->storeTomanPer1000Dinar((float) $rateInput);
        }

        if (array_key_exists('consignment_commission_rate', $validated)) {
            AppSetting::put(
                'almanahel.consignment_commission_rate',
                (float) $validated['consignment_commission_rate']
            );
        }

        if (array_key_exists('rate_notes', $validated)) {
            AppSetting::put('almanahel.rate_notes', $validated['rate_notes'] ?? '');
        }

        ActivityLogger::record(
            'settings',
            'updated',
            'به‌روزرسانی تنظیمات سیستم',
            null,
            $validated,
        );

        return response()->json(array_merge($this->settingsPayload(), [
            'message' => 'تنظیمات ذخیره شد',
        ]));
    }

    private function settingsPayload(): array
    {
        $rate = $this->resolveTomanPer1000Dinar();

        return [
            'low_stock_threshold' => (int) AppSetting::get(
                'almanahel.low_stock_threshold',
                config('almanahel.low_stock_threshold', 5)
            ),
            'toman_per_1000_dinar' => $rate,
            // Legacy alias for older clients — same value / meaning.
            'toman_to_dinar_rate' => $rate,
            'consignment_commission_rate' => (float) AppSetting::get(
                'almanahel.consignment_commission_rate',
                config('almanahel.consignment_commission_rate', 0.1)
            ),
            'rate_notes' => (string) (AppSetting::get('almanahel.rate_notes', '') ?? ''),
            'rate_updated_at' => AppSetting::get('almanahel.rate_updated_at'),
        ];
    }

    /**
     * Market quote: how many toman equal 1000 IQD.
     * Ignores legacy "dinar-per-toman" multipliers (typically tiny, e.g. 50).
     */
    private function resolveTomanPer1000Dinar(): float
    {
        $default = (float) config('almanahel.toman_per_1000_dinar', 120000);

        $stored = AppSetting::get('almanahel.toman_per_1000_dinar');
        if ($stored !== null && is_numeric($stored) && (float) $stored >= 1000) {
            return (float) $stored;
        }

        $legacy = AppSetting::get('almanahel.toman_to_dinar_rate');
        if ($legacy !== null && is_numeric($legacy) && (float) $legacy >= 1000) {
            $value = (float) $legacy;
            AppSetting::put('almanahel.toman_per_1000_dinar', $value);

            return $value;
        }

        return $default;
    }

    private function storeTomanPer1000Dinar(float $rate): void
    {
        AppSetting::put('almanahel.toman_per_1000_dinar', $rate);
        // Keep legacy key in sync so old cache/readers stay coherent.
        AppSetting::put('almanahel.toman_to_dinar_rate', $rate);
        AppSetting::put('almanahel.rate_updated_at', now()->toIso8601String());
    }

    private function transferNotifications($user): array
    {
        $isAdmin = in_array($user->role, ['super_admin', 'admin'], true);
        $isWarehouse = $user->role === 'warehouse_staff';
        $userBranch = (int) ($user->branch_id ?? 0);
        $visible = [];
        if ($userBranch) {
            $visible[] = $userBranch;
        }
        foreach ($user->iraq_only_visible_branches ?? [] as $id) {
            $visible[] = (int) $id;
        }
        $visible = array_values(array_unique(array_filter($visible)));

        $query = Transfer::with(['fromBranch', 'toBranch', 'user'])
            ->whereIn('status', ['pending', 'shipped', 'received'])
            ->latest();

        if (!$isAdmin) {
            if (!$visible && !$isWarehouse) {
                return [];
            }
            $query->where(function ($q) use ($visible, $user, $isWarehouse) {
                if ($visible) {
                    $q->whereIn('from_branch_id', $visible)
                        ->orWhereIn('to_branch_id', $visible);
                }
                if ($user?->id) {
                    $q->orWhere('user_id', $user->id);
                }
                if ($isWarehouse && !$visible) {
                    $q->orWhereHas('fromBranch', fn ($b) => $b->where('type', 'warehouse'))
                        ->orWhereHas('toBranch', fn ($b) => $b->where('type', 'warehouse'));
                }
            });
        }

        $rows = $query->limit(80)->get();
        $bookIds = $rows->flatMap(fn (Transfer $t) => collect($t->lineItems())->pluck('book_id'))
            ->filter()
            ->unique()
            ->values();
        $titles = $bookIds->isEmpty()
            ? collect()
            : Book::whereIn('id', $bookIds)->pluck('title', 'id');

        $out = [];
        foreach ($rows as $transfer) {
            $fromId = (int) $transfer->from_branch_id;
            $toId = (int) $transfer->to_branch_id;
            $isFrom = $isAdmin || $isWarehouse || ($userBranch && $userBranch === $fromId) || (int) $transfer->user_id === (int) $user->id;
            $isTo = $isAdmin || ($userBranch && $userBranch === $toId);

            $items = $transfer->lineItems();
            $qty = (int) collect($items)->sum(fn ($item) => (int) ($item['quantity'] ?? 0));
            $firstId = $items[0]['book_id'] ?? null;
            $bookTitle = $firstId ? (string) ($titles->get((int) $firstId) ?? 'کتاب') : 'کتاب';
            if (count($items) > 1) {
                $bookTitle .= ' (+'.(count($items) - 1).')';
            }

            $fromName = $transfer->fromBranch?->name ?? 'مبدأ';
            $toName = $transfer->toBranch?->name ?? 'مقصد';
            $payload = [
                'transfer_id'   => $transfer->id,
                'book_title'    => $bookTitle,
                'quantity'      => $qty,
                'from_branch'   => $fromName,
                'to_branch'     => $toName,
                'from_branch_id'=> $fromId,
                'to_branch_id'  => $toId,
                'status'        => $transfer->status,
                'created_at'    => optional($transfer->created_at)?->toIso8601String(),
                'updated_at'    => optional($transfer->updated_at)?->toIso8601String(),
            ];

            if (in_array($transfer->status, ['pending', 'shipped'], true) && $isTo) {
                $out[] = [
                    'type'    => 'transfer_sending',
                    'message' => "محموله «{$bookTitle}» از {$fromName} در راه است — تأیید دریافت کنید",
                    'data'    => $payload,
                ];
            }
            if (
                $transfer->status === 'received'
                && $isFrom
                && $transfer->updated_at
                && $transfer->updated_at->gte(now()->subDays(14))
            ) {
                $out[] = [
                    'type'    => 'transfer_received',
                    'message' => "{$toName} دریافت «{$bookTitle}» را تأیید کرد",
                    'data'    => $payload,
                ];
            }
        }

        return $out;
    }
}
