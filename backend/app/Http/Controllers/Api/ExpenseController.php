<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    private function isAdmin($user): bool
    {
        return in_array($user?->role, ['super_admin', 'admin'], true);
    }

    /** @return int[]|null null = unrestricted (admin) */
    private function visibleBranchIds($user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $ids = [];
        if ($user?->branch_id) {
            $ids[] = (int) $user->branch_id;
        }
        foreach ($user->iraq_only_visible_branches ?? [] as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function scopeToUser($query, $user)
    {
        $ids = $this->visibleBranchIds($user);
        if ($ids === null) {
            return $query;
        }
        if (!$ids) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('branch_id', $ids);
    }

    private function assertBranchAllowed($user, int $branchId): void
    {
        $ids = $this->visibleBranchIds($user);
        if ($ids === null) {
            return;
        }
        if (!in_array($branchId, $ids, true)) {
            abort(403, 'اجازه دسترسی به این شعبه را ندارید');
        }
    }

    private function assertExpenseAccess($user, Expense $expense): void
    {
        $this->assertBranchAllowed($user, (int) $expense->branch_id);
    }

    public function index(Request $request)
    {
        $query = Expense::with(['branch', 'user']);
        $this->scopeToUser($query, $request->user());

        if ($request->filled('branch_id')) {
            $this->assertBranchAllowed($request->user(), (int) $request->branch_id);
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

        $this->assertBranchAllowed($request->user(), (int) $validated['branch_id']);

        $expense = Expense::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        ActivityLogger::record(
            'expenses',
            'created',
            "ثبت هزینه {$validated['category']} — {$validated['amount']} {$validated['currency']}",
            $expense,
            [
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'category' => $expense->category,
                'date' => $expense->date,
            ],
            (int) $expense->branch_id,
        );

        return response()->json($expense->load(['branch', 'user']), 201);
    }

    public function update(Request $request, Expense $expense)
    {
        $this->assertExpenseAccess($request->user(), $expense);

        $validated = $request->validate([
            'branch_id'   => 'sometimes|required|exists:branches,id',
            'amount'      => 'sometimes|required|numeric|min:0',
            'currency'    => 'sometimes|required|in:toman,dinar',
            'category'    => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'date'        => 'sometimes|required|date',
        ]);

        if (isset($validated['branch_id'])) {
            $this->assertBranchAllowed($request->user(), (int) $validated['branch_id']);
        }

        $expense->update($validated);

        ActivityLogger::record(
            'expenses',
            'updated',
            "ویرایش هزینه #{$expense->id} — {$expense->category}",
            $expense,
            [
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'category' => $expense->category,
            ],
            (int) $expense->branch_id,
        );

        return response()->json($expense->fresh()->load(['branch', 'user']));
    }

    public function destroy(Request $request, Expense $expense)
    {
        $this->assertExpenseAccess($request->user(), $expense);

        $snapshot = [
            'expense_id' => $expense->id,
            'amount' => $expense->amount,
            'currency' => $expense->currency,
            'category' => $expense->category,
            'branch_id' => $expense->branch_id,
        ];
        $branchId = (int) $expense->branch_id;
        $expense->delete();

        ActivityLogger::record(
            'expenses',
            'deleted',
            "حذف هزینه #{$snapshot['expense_id']} — {$snapshot['category']}",
            null,
            $snapshot,
            $branchId,
        );

        return response()->json(['message' => 'هزینه با موفقیت حذف شد']);
    }
}
