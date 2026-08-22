<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Services\Reports\LedgerReportService;
use App\Services\Treasury\FinancialAccountBootstrap;
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
            'is_central_warehouse' => 'boolean',
            'is_intake_hub' => 'boolean',
            'is_iraq_store' => 'boolean',
            'supports_dinar' => 'boolean',
            'supports_toman' => 'boolean',
            'can_receive_inventory' => 'boolean',
            'can_set_pricing' => 'boolean',
            'can_sell' => 'boolean',
            'can_transfer' => 'boolean',
            'can_report' => 'boolean',
            'can_manage_alerts' => 'boolean',
        ]);

        $branch = Branch::create($validated);
        app(FinancialAccountBootstrap::class)->run(true);

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
            'is_central_warehouse' => 'boolean',
            'is_intake_hub' => 'boolean',
            'is_iraq_store' => 'boolean',
            'supports_dinar' => 'boolean',
            'supports_toman' => 'boolean',
            'can_receive_inventory' => 'boolean',
            'can_set_pricing' => 'boolean',
            'can_sell' => 'boolean',
            'can_transfer' => 'boolean',
            'can_report' => 'boolean',
            'can_manage_alerts' => 'boolean',
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
        $hasHistory = $branch->inventories()->exists()
            || \App\Models\Invoice::where('branch_id', $branch->id)->exists()
            || \App\Models\Expense::where('branch_id', $branch->id)->exists();

        if ($hasHistory) {
            $branch->update([
                'status' => 'inactive',
                'archived_at' => now(),
            ]);

            ActivityLogger::record(
                'branches',
                'updated',
                "بایگانی شعبه «{$branch->name}» (حذف فیزیکی مسدود به دلیل سابقه مالی)",
                $branch,
                ['archived' => true],
                (int) $branch->id,
            );

            return response()->json([
                'message' => 'شعبه به دلیل سابقه مالی بایگانی شد و حذف فیزیکی انجام نشد',
                'archived' => true,
                'branch' => $branch->fresh(),
            ]);
        }

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
    public function profit(Request $request, Branch $branch)
    {
        BranchAccess::assertCanViewFinancialReports($request->user());
        if ($request->user()->role === 'branch_manager' && (int) $request->user()->branch_id !== (int) $branch->id) {
            BranchAccess::deny('اجازه مشاهده گزارش شعبه دیگر را ندارید');
        }
        $period = app(LedgerReportService::class)->period(
            $request->input('date_from'),
            $request->input('date_to'),
            '1970-01-01',
            now()->toDateString()
        );

        return response()->json(app(LedgerReportService::class)->branchProfit($branch, $period['from'], $period['to']));
    }
}
