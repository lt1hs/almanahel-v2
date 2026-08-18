<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Expense;
use App\Models\Gift;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Check;
use App\Models\Book;
use App\Models\Transfer;
use App\Support\ActivityLogger;
use App\Support\IntakePolicy;
use App\Support\SalesCogs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    public function allBranchBalance(Request $request)
    {
        \App\Support\Authorization\BranchAccess::assertCanViewAllReports($request->user());

        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();

        $branches = Branch::where('type', 'store')
            ->orderBy('id')
            ->get()
            ->unique(fn ($b) => mb_strtolower(trim((string) $b->name)))
            ->values()
            ->map(function ($branch) use ($dateFrom, $dateTo) {
            $salesToman = Invoice::where('branch_id', $branch->id)
                ->where('currency', 'toman')
                ->where(function ($q) {
                    $q->whereNull('type')->orWhere('type', 'sale');
                })
                ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
                ->sum('total');

            $salesDinar = Invoice::where('branch_id', $branch->id)
                ->where('currency', 'dinar')
                ->where(function ($q) {
                    $q->whereNull('type')->orWhere('type', 'sale');
                })
                ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
                ->sum('total');

            $expensesToman = Expense::where('branch_id', $branch->id)
                ->where('currency', 'toman')
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->sum('amount');

            $expensesDinar = Expense::where('branch_id', $branch->id)
                ->where('currency', 'dinar')
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->sum('amount');

            $giftCostsToman = Gift::where('branch_id', $branch->id)
                ->where('currency', 'toman')
                ->whereBetween('gifted_at', [$dateFrom, $dateTo])
                ->sum('cost_value');

            $giftCostsDinar = Gift::where('branch_id', $branch->id)
                ->where('currency', 'dinar')
                ->whereBetween('gifted_at', [$dateFrom, $dateTo])
                ->sum('cost_value');

            $cogsToman = SalesCogs::forBranch((int) $branch->id, 'toman', $dateFrom, $dateTo);
            $cogsDinar = SalesCogs::forBranch((int) $branch->id, 'dinar', $dateFrom, $dateTo);

            $pendingCreditToman = Invoice::where('branch_id', $branch->id)
                ->where('payment_method', 'credit')
                ->where('payment_status', 'pending')
                ->where('currency', 'toman')
                ->sum('total');

            $pendingCreditDinar = Invoice::where('branch_id', $branch->id)
                ->where('payment_method', 'credit')
                ->where('payment_status', 'pending')
                ->where('currency', 'dinar')
                ->sum('total');

            return [
                'branch'              => $branch,
                'revenue_toman'       => $salesToman,
                'revenue_dinar'       => $salesDinar,
                'cogs_toman'          => $cogsToman,
                'cogs_dinar'          => $cogsDinar,
                'expenses_toman'      => $expensesToman,
                'expenses_dinar'      => $expensesDinar,
                'gift_costs_toman'    => $giftCostsToman,
                'gift_costs_dinar'    => $giftCostsDinar,
                'pending_credit_toman'=> $pendingCreditToman,
                'pending_credit_dinar'=> $pendingCreditDinar,
                'pending_credit'      => $pendingCreditToman + $pendingCreditDinar,
                'net_profit_toman'    => $salesToman - $cogsToman - $expensesToman - $giftCostsToman,
                'net_profit_dinar'    => $salesDinar - $cogsDinar - $expensesDinar - $giftCostsDinar,
            ];
        });

        return response()->json($branches);
    }

    public function dashboardStats(Request $request)
    {
        $user = $request->user();
        $branchFilter = ($user->role === 'branch_manager') ? $user->branch_id : null;
        $isAdmin = in_array($user->role, ['super_admin', 'admin'], true);

        $totalTitles = $isAdmin && !$branchFilter
            ? Book::count()
            : (int) Inventory::query()
                ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
                ->distinct()
                ->count('book_id');

        $totalStock = (float) Inventory::query()
            ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
            ->sum('quantity');

        $todaySalesToman = Invoice::where('currency', 'toman')
            ->whereDate('created_at', today())
            ->when($branchFilter, fn($q) => $q->where('branch_id', $branchFilter))
            ->sum('total');

        $todaySalesDinar = Invoice::where('currency', 'dinar')
            ->whereDate('created_at', today())
            ->when($branchFilter, fn($q) => $q->where('branch_id', $branchFilter))
            ->sum('total');

        $todayInvoiceCount = Invoice::whereDate('created_at', today())
            ->when($branchFilter, fn($q) => $q->where('branch_id', $branchFilter))
            ->count();

        $totalSuppliers = \App\Models\Supplier::count();
        $totalBranches = Branch::where('status', 'active')->count();
        $pendingChecks  = Check::where('status', 'pending')
            ->where('due_date', '<=', now()->addDays(7))->count();

        $globalThreshold = (int) Cache::get('almanahel.low_stock_threshold', config('almanahel.low_stock_threshold', 5));
        $lowStockCount = Inventory::query()
            ->join('books', 'inventories.book_id', '=', 'books.id')
            ->when($branchFilter, fn ($q) => $q->where('inventories.branch_id', $branchFilter))
            ->where('inventories.quantity', '>', 0)
            ->whereRaw(
                'inventories.quantity <= COALESCE(books.low_stock_threshold, ?)',
                [$globalThreshold]
            )
            ->count();

        $outOfStockCount = $isAdmin && !$branchFilter
            ? Book::whereDoesntHave('inventories', fn ($q) => $q->where('quantity', '>', 0))->count()
            : 0;

        $inventoryValues = $this->inventoryAssetValues($branchFilter ? (int) $branchFilter : null);

        return response()->json([
            'total_titles'           => $totalTitles,
            'total_stock'            => $totalStock,
            // Back-compat: previously this was stock copies
            'total_books'            => $totalTitles,
            'today_sales_toman'      => $todaySalesToman,
            'today_sales_dinar'      => $todaySalesDinar,
            'today_invoice_count'    => $todayInvoiceCount,
            'total_suppliers'        => $totalSuppliers,
            'total_branches'         => $totalBranches,
            'low_stock_count'        => $lowStockCount,
            'out_of_stock_count'     => $outOfStockCount,
            'pending_checks'         => $pendingChecks,
            'inventory_value_toman'  => $inventoryValues['toman'],
            'inventory_value_dinar'  => $inventoryValues['dinar'],
        ]);
    }

    /**
     * Sell-side inventory asset value per currency — never converts toman↔dinar.
     * Missing local prices may fall back to the same currency on another branch of the book.
     *
     * @return array{toman: float, dinar: float}
     */
    private function inventoryAssetValues(?int $branchId): array
    {
        $rows = Inventory::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('quantity', '>', 0)
            ->get(['book_id', 'quantity', 'price_toman', 'price_dinar']);

        $needTomanIds = $rows
            ->filter(fn ($inv) => !(float) ($inv->price_toman ?? 0))
            ->pluck('book_id')
            ->unique()
            ->values();
        $needDinarIds = $rows
            ->filter(fn ($inv) => !(float) ($inv->price_dinar ?? 0))
            ->pluck('book_id')
            ->unique()
            ->values();

        $tomanDonors = collect();
        if ($needTomanIds->isNotEmpty()) {
            $tomanDonors = Inventory::query()
                ->whereIn('book_id', $needTomanIds)
                ->where('price_toman', '>', 0)
                ->get(['book_id', 'price_toman'])
                ->groupBy('book_id');
        }

        $dinarDonors = collect();
        if ($needDinarIds->isNotEmpty()) {
            $dinarDonors = Inventory::query()
                ->whereIn('book_id', $needDinarIds)
                ->where('price_dinar', '>', 0)
                ->get(['book_id', 'price_dinar'])
                ->groupBy('book_id');
        }

        $toman = 0.0;
        $dinar = 0.0;

        foreach ($rows as $inv) {
            $pt = (float) ($inv->price_toman ?? 0);
            $pd = (float) ($inv->price_dinar ?? 0);
            if ($pt <= 0) {
                $pt = (float) ($tomanDonors->get($inv->book_id)?->first()?->price_toman ?? 0);
            }
            if ($pd <= 0) {
                $pd = (float) ($dinarDonors->get($inv->book_id)?->first()?->price_dinar ?? 0);
            }

            $qty = (float) $inv->quantity;
            if ($pt > 0) {
                $toman += $qty * $pt;
            }
            if ($pd > 0) {
                $dinar += $qty * $pd;
            }
        }

        return ['toman' => $toman, 'dinar' => $dinar];
    }

    public function monthlyTrends(Request $request)
    {
        $months   = min(12, max(3, (int) ($request->months ?? 6)));
        $currency = $request->currency ?? 'toman';
        $branchId = $request->branch_id;

        $data = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now()->subMonths($i)->startOfMonth();
            $end   = now()->subMonths($i)->endOfMonth();

            $storeIds = Branch::where('type', 'store')
                ->orderBy('id')
                ->get()
                ->unique(fn ($b) => mb_strtolower(trim((string) $b->name)))
                ->pluck('id');

            $salesQuery = Invoice::where('currency', $currency)
                ->where(function ($q) {
                    $q->whereNull('type')->orWhere('type', 'sale');
                })
                ->whereBetween('created_at', [$start, $end]);

            if ($branchId) {
                $salesQuery->where('branch_id', $branchId);
            } else {
                $salesQuery->whereIn('branch_id', $storeIds);
            }
            $sales = $salesQuery->sum('total');

            $expenses = Expense::where('currency', $currency)
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->when(!$branchId, fn($q) => $q->whereIn('branch_id', $storeIds))
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->sum('amount');

            $gifts = Gift::where('currency', $currency)
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->when(!$branchId, fn($q) => $q->whereIn('branch_id', $storeIds))
                ->whereBetween('gifted_at', [$start->toDateString(), $end->toDateString()])
                ->sum('cost_value');

            $cogs = 0.0;
            $cogsBranchIds = $branchId ? [(int) $branchId] : $storeIds->map(fn ($id) => (int) $id)->all();
            foreach ($cogsBranchIds as $id) {
                $cogs += SalesCogs::forBranch(
                    (int) $id,
                    $currency,
                    $start->toDateString(),
                    $end->toDateString()
                );
            }

            $data[] = [
                'label'  => $start->format('Y-m'),
                'month'  => (int) $start->format('n'),
                'sales'  => (float) $sales,
                'cogs'   => (float) $cogs,
                'profit' => (float) ($sales - $cogs - $expenses - $gifts),
            ];
        }

        return response()->json($data);
    }

    public function topBooks(Request $request)
    {
        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();

        $top = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('books', 'invoice_items.book_id', '=', 'books.id')
            ->whereBetween(DB::raw('DATE(invoices.created_at)'), [$dateFrom, $dateTo])
            ->when($request->branch_id, fn($q) => $q->where('invoices.branch_id', $request->branch_id))
            ->when($request->boolean('iraq_only'), fn($q) => $q->where('books.iraq_only', true))
            ->when($request->boolean('exclude_iraq_only'), fn($q) => $q->where('books.iraq_only', false))
            ->groupBy('invoice_items.book_id', 'books.title', 'books.author')
            ->select(
                'invoice_items.book_id',
                'books.title',
                'books.author',
                DB::raw('SUM(invoice_items.quantity) as total_sold'),
                DB::raw('SUM(invoice_items.actual_price * invoice_items.quantity) as total_revenue')
            )
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get();

        return response()->json($top);
    }

    public function notifications(Request $request)
    {
        $user = $request->user();
        $globalThreshold = (int) Cache::get('almanahel.low_stock_threshold', config('almanahel.low_stock_threshold', 5));
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

    /** Iraq branch P&L split by lot origin (iraq_local vs qom_distributed). */
    public function iraqProfit(Request $request)
    {
        \App\Support\Authorization\BranchAccess::assertCanViewAllReports($request->user());

        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();

        $iraqBranches = Branch::query()
            ->where('type', 'store')
            ->where(function ($q) {
                $q->where('country', 'عراق')
                    ->orWhere('is_iraq_store', true);
            })
            ->get();

        $iraqBranch = $iraqBranches->first();
        $branchIds = $iraqBranches->pluck('id')->all();

        $empty = [
            'revenue' => 0, 'cogs' => 0, 'expenses' => 0, 'gifts' => 0, 'net_profit' => 0, 'sales_count' => 0,
            'returns' => 0,
        ];

        $compute = function (array $origins) use ($branchIds, $dateFrom, $dateTo, $empty) {
            if (!$branchIds) {
                return $empty;
            }

            $hasLots = Schema::hasTable('sale_lot_allocations') && Schema::hasTable('stock_lots');

            $invoiceQuery = DB::table('invoices')
                ->whereIn('branch_id', $branchIds)
                ->where(function ($q) {
                    $q->whereNull('type')->orWhere('type', 'sale');
                })
                ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo]);

            if ($hasLots && $origins) {
                $invoiceIds = DB::table('invoice_items')
                    ->join('sale_lot_allocations', 'sale_lot_allocations.invoice_item_id', '=', 'invoice_items.id')
                    ->join('stock_lots', 'stock_lots.id', '=', 'sale_lot_allocations.stock_lot_id')
                    ->whereIn('stock_lots.origin', $origins)
                    ->pluck('invoice_items.invoice_id')
                    ->unique();
                $invoiceQuery->whereIn('id', $invoiceIds);
            } elseif ($origins === ['iraq_local']) {
                $invoiceQuery->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('invoice_items')
                        ->join('books', 'books.id', '=', 'invoice_items.book_id')
                        ->whereColumn('invoice_items.invoice_id', 'invoices.id')
                        ->where('books.iraq_only', true);
                });
            } elseif ($origins === ['qom_distributed']) {
                $invoiceQuery->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('invoice_items')
                        ->join('books', 'books.id', '=', 'invoice_items.book_id')
                        ->whereColumn('invoice_items.invoice_id', 'invoices.id')
                        ->where('books.iraq_only', false);
                });
            }

            $revenue = (float) (clone $invoiceQuery)->sum('total');
            $salesCount = (int) (clone $invoiceQuery)->count();

            $cogs = 0.0;
            foreach ($branchIds as $bid) {
                foreach (['toman', 'dinar'] as $cur) {
                    $cogs += SalesCogs::forBranch($bid, $cur, $dateFrom, $dateTo);
                }
            }

            $expenses = (float) Expense::whereIn('branch_id', $branchIds)
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->sum('amount');
            $gifts = (float) Gift::whereIn('branch_id', $branchIds)
                ->whereBetween(DB::raw('DATE(gifted_at)'), [$dateFrom, $dateTo])
                ->sum('cost_value');
            $returns = (float) DB::table('customer_returns')
                ->whereIn('branch_id', $branchIds)
                ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
                ->sum('refund_amount');

            $netRevenue = $revenue - $returns;
            // Note: expenses/gifts/cogs are branch-level (not origin-split) when lots absent;
            // with lots, cogs still branch-level sum — documented limitation until origin-filtered COGS helper.
            return [
                'revenue' => round($netRevenue, 2),
                'cogs' => round($cogs, 2),
                'expenses' => round($expenses, 2),
                'gifts' => round($gifts, 2),
                'returns' => round($returns, 2),
                'net_profit' => round($netRevenue - $cogs - $expenses - $gifts, 2),
                'sales_count' => $salesCount,
            ];
        };

        $iraqLocal = $compute(['iraq_local']);
        $distributed = $compute(['qom_distributed']);
        $combined = $compute([]);

        // Compatibility aliases
        $iraqOnlyRevenue = $iraqLocal['revenue'];
        $distributedRevenue = $distributed['revenue'];

        return response()->json([
            'branch' => $iraqBranch,
            'branches' => $iraqBranches,
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            // UI fields
            'revenue' => $combined['revenue'],
            'expenses' => $combined['expenses'],
            'net_profit' => $combined['net_profit'],
            'sales_count' => $combined['sales_count'],
            'cogs' => $combined['cogs'],
            'gifts' => $combined['gifts'],
            'returns' => $combined['returns'],
            // Origin splits
            'iraq_local' => $iraqLocal,
            'qom_distributed' => $distributed,
            'combined' => $combined,
            // Legacy aliases
            'iraq_only_revenue' => $iraqOnlyRevenue,
            'distributed_revenue' => $distributedRevenue,
            'total_iraq_revenue' => $combined['revenue'],
        ]);
    }

    /** Books shipped from Qom hub to other branches */
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
        return response()->json([
            'low_stock_threshold' => (int) Cache::get(
                'almanahel.low_stock_threshold',
                config('almanahel.low_stock_threshold', 5)
            ),
            'toman_to_dinar_rate' => (float) Cache::get(
                'almanahel.toman_to_dinar_rate',
                config('almanahel.toman_to_dinar_rate', 50)
            ),
            'consignment_commission_rate' => (float) Cache::get(
                'almanahel.consignment_commission_rate',
                config('almanahel.consignment_commission_rate', 0.1)
            ),
            'rate_notes' => Cache::get('almanahel.rate_notes', ''),
            'rate_updated_at' => Cache::get('almanahel.rate_updated_at'),
        ]);
    }

    public function updateSettings(Request $request)
    {
        \App\Support\Authorization\BranchAccess::assertCanManageSettings($request->user());

        $validated = $request->validate([
            'low_stock_threshold' => 'sometimes|integer|min:1|max:100',
            'toman_to_dinar_rate' => 'sometimes|numeric|min:0.0001|max:1000000',
            'consignment_commission_rate' => 'sometimes|numeric|min:0|max:0.5',
            'rate_notes' => 'sometimes|nullable|string|max:500',
        ]);

        if (array_key_exists('low_stock_threshold', $validated)) {
            Cache::forever('almanahel.low_stock_threshold', $validated['low_stock_threshold']);
        }

        if (array_key_exists('toman_to_dinar_rate', $validated)) {
            Cache::forever('almanahel.toman_to_dinar_rate', $validated['toman_to_dinar_rate']);
            Cache::forever('almanahel.rate_updated_at', now()->toIso8601String());
        }

        if (array_key_exists('consignment_commission_rate', $validated)) {
            Cache::forever('almanahel.consignment_commission_rate', $validated['consignment_commission_rate']);
        }

        if (array_key_exists('rate_notes', $validated)) {
            Cache::forever('almanahel.rate_notes', $validated['rate_notes'] ?? '');
        }

        ActivityLogger::record(
            'settings',
            'updated',
            'به‌روزرسانی تنظیمات سیستم',
            null,
            $validated,
        );

        return response()->json([
            'low_stock_threshold' => (int) Cache::get(
                'almanahel.low_stock_threshold',
                config('almanahel.low_stock_threshold', 5)
            ),
            'toman_to_dinar_rate' => (float) Cache::get(
                'almanahel.toman_to_dinar_rate',
                config('almanahel.toman_to_dinar_rate', 50)
            ),
            'rate_notes' => Cache::get('almanahel.rate_notes', ''),
            'rate_updated_at' => Cache::get('almanahel.rate_updated_at'),
            'message' => 'تنظیمات ذخیره شد',
        ]);
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
