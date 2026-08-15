<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\SalesCogs;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    private function branchListQuery()
    {
        return Branch::query()
            ->select('branches.*')
            ->withCount(['users'])
            ->withSum('inventories as total_stock', 'quantity')
            ->addSelect([
                'total_titles' => Inventory::query()
                    ->selectRaw('count(distinct book_id)')
                    ->whereColumn('branch_id', 'branches.id')
                    ->where('quantity', '>', 0),
            ]);
    }

    public function index(Request $request)
    {
        if ($request->boolean('lite')) {
            return response()->json(
                Branch::query()
                    ->where('status', 'active')
                    ->orderBy('type')
                    ->orderBy('name')
                    ->orderBy('id')
                    ->get(['id', 'name', 'type', 'city', 'status'])
                    ->unique(fn (Branch $b) => mb_strtolower(trim($b->name)))
                    ->values()
            );
        }

        return response()->json($this->branchListQuery()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255|unique:branches,name',
            'city'    => 'required|string|max:100',
            'country' => 'required|string|max:100',
            'type'    => 'in:warehouse,store',
            'status'  => 'in:active,inactive',
        ]);

        $branch = Branch::create($validated);

        ActivityLogger::record(
            'branches',
            'created',
            "ایجاد شعبه «{$branch->name}»",
            $branch,
            ['name' => $branch->name, 'city' => $branch->city, 'type' => $branch->type],
            (int) $branch->id,
        );

        return response()->json(
            $this->branchListQuery()->findOrFail($branch->id),
            201
        );
    }

    public function show(Branch $branch)
    {
        return response()->json($branch->load(['users', 'inventories.book']));
    }

    public function update(Request $request, Branch $branch)
    {
        $validated = $request->validate([
            'name'    => 'sometimes|required|string|max:255|unique:branches,name,' . $branch->id,
            'city'    => 'sometimes|required|string|max:100',
            'country' => 'sometimes|required|string|max:100',
            'type'    => 'in:warehouse,store',
            'status'  => 'in:active,inactive',
        ]);

        $branch->update($validated);

        ActivityLogger::record(
            'branches',
            'updated',
            "ویرایش شعبه «{$branch->name}»",
            $branch,
            ['name' => $branch->name, 'city' => $branch->city, 'status' => $branch->status],
            (int) $branch->id,
        );

        return response()->json(
            $this->branchListQuery()->findOrFail($branch->id)
        );
    }

    public function destroy(Branch $branch)
    {
        $snapshot = [
            'branch_id' => $branch->id,
            'name' => $branch->name,
            'city' => $branch->city,
        ];
        $branch->delete();

        ActivityLogger::record(
            'branches',
            'deleted',
            "حذف شعبه «{$snapshot['name']}»",
            null,
            $snapshot,
        );

        return response()->json(['message' => 'شعبه با موفقیت حذف شد']);
    }

    /** Get per-branch profit summary */
    public function profit(Branch $branch)
    {
        $sales = \App\Models\Invoice::where('branch_id', $branch->id)
            ->where(function ($q) {
                $q->whereNull('type')->orWhere('type', 'sale');
            })
            ->get();

        $expenses = \App\Models\Expense::where('branch_id', $branch->id)->get();
        $gifts    = \App\Models\Gift::where('branch_id', $branch->id)->get();

        $totalRevenueToman  = $sales->where('currency', 'toman')->sum('total');
        $totalRevenueDinar  = $sales->where('currency', 'dinar')->sum('total');
        $totalExpenseToman  = $expenses->where('currency', 'toman')->sum('amount');
        $totalExpenseDinar  = $expenses->where('currency', 'dinar')->sum('amount');
        $totalGiftCostToman = $gifts->where('currency', 'toman')->sum('cost_value');
        $totalGiftCostDinar = $gifts->where('currency', 'dinar')->sum('cost_value');

        $from = '1970-01-01';
        $to = now()->toDateString();
        $cogsToman = SalesCogs::forBranch((int) $branch->id, 'toman', $from, $to);
        $cogsDinar = SalesCogs::forBranch((int) $branch->id, 'dinar', $from, $to);

        return response()->json([
            'branch'              => $branch,
            'revenue_toman'       => $totalRevenueToman,
            'revenue_dinar'       => $totalRevenueDinar,
            'cogs_toman'          => $cogsToman,
            'cogs_dinar'          => $cogsDinar,
            'expenses_toman'      => $totalExpenseToman,
            'expenses_dinar'      => $totalExpenseDinar,
            'gift_costs_toman'    => $totalGiftCostToman,
            'gift_costs_dinar'    => $totalGiftCostDinar,
            'net_profit_toman'    => $totalRevenueToman - $cogsToman - $totalExpenseToman - $totalGiftCostToman,
            'net_profit_dinar'    => $totalRevenueDinar - $cogsDinar - $totalExpenseDinar - $totalGiftCostDinar,
        ]);
    }
}
