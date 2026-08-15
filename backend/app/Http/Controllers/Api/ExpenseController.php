<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::with(['branch', 'user']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('currency')) {
            $query->where('currency', $request->currency);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);

        return response()->json($query->latest('date')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'   => 'required|exists:branches,id',
            'amount'      => 'required|numeric|min:0',
            'currency'    => 'required|in:toman,dinar',
            'category'    => 'required|string|max:100',
            'description' => 'nullable|string',
            'date'        => 'required|date',
        ]);

        $expense = Expense::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        return response()->json($expense, 201);
    }

    public function update(Request $request, Expense $expense)
    {
        $validated = $request->validate([
            'amount'      => 'sometimes|required|numeric|min:0',
            'currency'    => 'sometimes|required|in:toman,dinar',
            'category'    => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'date'        => 'sometimes|required|date',
        ]);
        $expense->update($validated);
        return response()->json($expense);
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();
        return response()->json(['message' => 'هزینه با موفقیت حذف شد']);
    }
}
