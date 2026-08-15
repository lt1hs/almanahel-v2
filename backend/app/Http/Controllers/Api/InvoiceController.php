<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Inventory;
use App\Models\Check;
use App\Support\ConsignmentSync;
use App\Support\StockMovementLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Invoice::with(['items.book', 'branch', 'user']);

        if ($user->role === 'branch_manager' && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
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
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'payment_method'   => 'required|in:cash,card,check,credit',
            'currency'         => 'required|in:toman,dinar',
            'customer_name'    => 'required_if:payment_method,credit|nullable|string|max:255',
            'customer_phone'   => 'nullable|string|max:30',
            'notes'            => 'nullable|string',
            'due_date'         => 'required_if:payment_method,check,credit|nullable|date',
            'type'             => 'in:sale,gift',
            'items'            => 'required|array|min:1',
            'items.*.book_id'      => 'required|exists:books,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.unit_price'   => 'required|numeric|min:0',
            'items.*.actual_price' => 'required|numeric|min:0',
            'items.*.discount'     => 'nullable|numeric|min:0',
            'check_number'     => 'required_if:payment_method,check|nullable|string',
            'bank_name'        => 'nullable|string',
            'payer_name'       => 'required_if:payment_method,check|nullable|string',
            'payer_phone'      => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            foreach ($validated['items'] as $item) {
                $inventory = Inventory::where('branch_id', $validated['branch_id'])
                    ->where('book_id', $item['book_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$inventory || $inventory->quantity < $item['quantity']) {
                    return response()->json([
                        'message' => 'موجودی کافی برای یکی از کتاب‌ها وجود ندارد',
                        'book_id' => $item['book_id'],
                    ], 422);
                }
            }

            $grossSubtotal = 0;
            $discountTotal = 0;
            foreach ($validated['items'] as $item) {
                $grossSubtotal += $item['actual_price'] * $item['quantity'];
                $discountTotal += ($item['discount'] ?? 0) * $item['quantity'];
            }
            $netTotal = max(0, $grossSubtotal - $discountTotal);

            $invoice = Invoice::create([
                'branch_id'       => $validated['branch_id'],
                'user_id'         => $request->user()->id,
                'invoice_number'  => 'INV-' . strtoupper(Str::random(8)),
                'payment_method'  => $validated['payment_method'],
                'payment_status'  => in_array($validated['payment_method'], ['check', 'credit']) ? 'pending' : 'paid',
                'currency'        => $validated['currency'],
                'subtotal'        => $grossSubtotal,
                'discount_amount' => $discountTotal,
                'total'           => $netTotal,
                'customer_name'   => $validated['customer_name'] ?? null,
                'customer_phone'  => $validated['customer_phone'] ?? null,
                'notes'           => $validated['notes'] ?? null,
                'due_date'        => $validated['due_date'] ?? null,
                'type'            => $validated['type'] ?? 'sale',
            ]);

            foreach ($validated['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id'   => $invoice->id,
                    'book_id'      => $item['book_id'],
                    'quantity'     => $item['quantity'],
                    'unit_price'   => $item['unit_price'],
                    'actual_price' => $item['actual_price'],
                    'discount'     => $item['discount'] ?? 0,
                ]);

                $inventory = Inventory::where('branch_id', $validated['branch_id'])
                    ->where('book_id', $item['book_id'])
                    ->lockForUpdate()
                    ->first();

                if ($inventory) {
                    $inventory->decrement('quantity', $item['quantity']);
                    ConsignmentSync::incrementSold($inventory, $item['book_id'], $item['quantity']);

                    StockMovementLogger::log(
                        (int) $validated['branch_id'],
                        (int) $item['book_id'],
                        'out',
                        (int) $item['quantity'],
                        'other',
                        $request->user()->name,
                        "فروش — فاکتور {$invoice->invoice_number}",
                    );
                }
            }

            if ($validated['payment_method'] === 'check') {
                Check::create([
                    'invoice_id'   => $invoice->id,
                    'branch_id'    => $validated['branch_id'],
                    'check_number' => $validated['check_number'],
                    'bank_name'    => $validated['bank_name'] ?? null,
                    'payer_name'   => $validated['payer_name'] ?? $validated['customer_name'] ?? 'نامشخص',
                    'payer_phone'  => $validated['payer_phone'] ?? $validated['customer_phone'] ?? null,
                    'amount'       => $netTotal,
                    'currency'     => $validated['currency'],
                    'due_date'     => $validated['due_date'],
                ]);
            }

            return response()->json($invoice->load(['items.book', 'branch', 'user']), 201);
        });
    }

    public function show(Invoice $invoice)
    {
        $user = request()->user();
        if ($user->role === 'branch_manager' && $user->branch_id && (int) $invoice->branch_id !== (int) $user->branch_id) {
            return response()->json(['message' => 'دسترسی غیرمجاز'], 403);
        }

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

        if ($user->role === 'branch_manager' && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
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
        $user = $request->user();
        if ($user->role === 'branch_manager' && $user->branch_id && (int) $check->branch_id !== (int) $user->branch_id) {
            return response()->json(['message' => 'دسترسی غیرمجاز'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:pending,cleared,bounced',
        ]);
        $check->update($validated);

        if ($validated['status'] === 'cleared' && $check->invoice) {
            $check->invoice->update(['payment_status' => 'paid']);
        }
        if ($validated['status'] === 'bounced' && $check->invoice) {
            $check->invoice->update(['payment_status' => 'overdue']);
        }
        if ($validated['status'] === 'pending' && $check->invoice) {
            $check->invoice->update(['payment_status' => 'pending']);
        }

        return response()->json($check->load(['invoice', 'branch']));
    }

    public function credits(Request $request)
    {
        $user = $request->user();

        // Sync overdue: pending credits past due date
        Invoice::query()
            ->where('payment_method', 'credit')
            ->where('payment_status', 'pending')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['payment_status' => 'overdue']);

        $query = Invoice::with(['branch', 'user', 'items.book'])
            ->where('payment_method', 'credit');

        if ($user->role === 'branch_manager' && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
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
            ->whereIn('payment_status', ['pending', 'overdue'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()]);

        if ($user->role === 'branch_manager' && $user->branch_id) {
            $dueSoonQuery->where('branch_id', $user->branch_id);
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

        $user = $request->user();
        if ($user->role === 'branch_manager' && $user->branch_id && (int) $invoice->branch_id !== (int) $user->branch_id) {
            return response()->json(['message' => 'دسترسی غیرمجاز'], 403);
        }

        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid,overdue',
        ]);

        $invoice->update(['payment_status' => $validated['payment_status']]);

        return response()->json($invoice->load(['branch', 'user', 'items.book']));
    }
}
