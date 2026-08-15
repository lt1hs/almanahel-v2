<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\Inventory;
use App\Models\ConsignmentReceipt;
use App\Support\StockMovementLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftController extends Controller
{
    public function index(Request $request)
    {
        $query = Gift::query()
            ->select([
                'id', 'branch_id', 'book_id', 'user_id', 'supplier_id',
                'quantity', 'recipient_name', 'cost_value', 'currency',
                'is_consignment', 'accounting_status', 'gifted_at', 'reason',
            ])
            ->with([
                'book:id,title,author',
                'branch:id,name',
                'supplier:id,name',
            ])
            ->latest('gifted_at');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('status')) {
            $query->where('accounting_status', $request->status);
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('recipient_name', 'like', $q)
                    ->orWhereHas('book', fn ($b) => $b->where('title', 'like', $q));
            });
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'book_id'          => 'required|exists:books,id',
            'quantity'         => 'required|integer|min:1',
            'recipient_name'   => 'required|string|max:255',
            'recipient_phone'  => 'nullable|string|max:30',
            'reason'           => 'nullable|string',
            'cost_value'       => 'required|numeric|min:0',
            'currency'         => 'required|in:toman,dinar',
            'is_consignment'   => 'boolean',
            'supplier_id'      => 'nullable|exists:suppliers,id',
            'gifted_at'        => 'required|date',
        ]);

        if (($validated['is_consignment'] ?? false) && empty($validated['supplier_id'])) {
            return response()->json(['message' => 'برای هدیه امانی، انتخاب تأمین‌کننده الزامی است'], 422);
        }

        return DB::transaction(function () use ($request, $validated) {
            $inventory = Inventory::where('branch_id', $validated['branch_id'])
                ->where('book_id', $validated['book_id'])
                ->lockForUpdate()
                ->first();

            if (!$inventory || $inventory->quantity < $validated['quantity']) {
                return response()->json(['message' => 'موجودی کافی برای اهدای این کتاب وجود ندارد'], 422);
            }

            $gift = Gift::create([
                ...$validated,
                'user_id'           => $request->user()->id,
                'accounting_status' => 'pending',
            ]);

            $inventory->decrement('quantity', $validated['quantity']);

            StockMovementLogger::log(
                (int) $validated['branch_id'],
                (int) $validated['book_id'],
                'out',
                (int) $validated['quantity'],
                'other',
                $request->user()->name,
                "هدیه به {$validated['recipient_name']}",
            );

            return response()->json($gift->load(['book', 'branch', 'supplier']), 201);
        });
    }

    public function show(Gift $gift)
    {
        return response()->json($gift->load(['book', 'branch', 'supplier', 'user']));
    }

    public function updateStatus(Request $request, Gift $gift)
    {
        $validated = $request->validate([
            'accounting_status' => 'required|in:pending,settled',
        ]);

        $gift->update($validated);

        if ($validated['accounting_status'] === 'settled' && $gift->is_consignment && $gift->supplier_id) {
            ConsignmentReceipt::create([
                'supplier_id'    => $gift->supplier_id,
                'branch_id'      => $gift->branch_id,
                'user_id'        => $request->user()->id,
                'receipt_number' => 'GIFT-' . strtoupper(substr(uniqid(), -8)),
                'status'         => 'settled',
                'currency'       => $gift->currency,
                'total_value'    => $gift->cost_value,
                'settled_amount' => $gift->cost_value,
                'received_at'    => $gift->gifted_at,
                'notes'          => "تسویه هدیه به {$gift->recipient_name}",
            ]);
        }

        return response()->json($gift->load(['book', 'branch', 'supplier']));
    }
}
