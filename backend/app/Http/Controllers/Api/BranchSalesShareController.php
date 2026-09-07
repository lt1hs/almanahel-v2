<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BranchShare\BranchSalesShareService;
use App\Services\Reports\LedgerReportService;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Support\BranchShareFlags;
use Illuminate\Http\Request;

class BranchSalesShareController extends Controller
{
    public function preview(Request $request, BranchSalesShareService $service)
    {
        return response()->json($service->preview($this->validated($request), $request->user()));
    }

    public function store(Request $request, BranchSalesShareService $service)
    {
        return response()->json($service->apply($this->validated($request, true), $request->user()), 201);
    }

    public function updateEnabled(Request $request)
    {
        BranchAccess::assertCanManageSettings($request->user());
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);
        $enabled = (bool) $validated['enabled'];
        BranchShareFlags::setEnabled($enabled);
        ActivityLogger::record(
            'finance',
            $enabled ? 'branch_sales_share_enabled' : 'branch_sales_share_disabled',
            $enabled ? 'فعال‌سازی سهم مدیریتی شعب' : 'خاموش کردن سهم مدیریتی شعب',
            null,
            ['enabled' => $enabled],
            null,
            $request->user()->id,
        );

        return response()->json(['enabled' => BranchShareFlags::enabled()]);
    }

    public function rules(Request $request, BranchSalesShareService $service)
    {
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;

        return response()->json($service->currentRules($request->user(), $branchId));
    }

    public function history(Request $request, BranchSalesShareService $service)
    {
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;

        return response()->json($service->history($request->user(), $branchId));
    }

    public function summary(Request $request, BranchSalesShareService $service)
    {
        BranchAccess::assertCanViewFinancialReports($request->user());
        $period = app(LedgerReportService::class)->period(
            $request->input('date_from'),
            $request->input('date_to'),
            '1970-01-01',
            now()->toDateString()
        );
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;
        if ($request->user()->role === 'branch_manager') {
            $branchId = $request->user()->branch_id ? (int) $request->user()->branch_id : $branchId;
        }

        return response()->json($service->summary($request->user(), $period['from'], $period['to'], $branchId) + [
            'enabled' => BranchShareFlags::enabled(),
        ]);
    }

    public function invoices(Request $request, BranchSalesShareService $service)
    {
        BranchAccess::assertCanViewFinancialReports($request->user());
        $period = app(LedgerReportService::class)->period(
            $request->input('date_from'),
            $request->input('date_to'),
            '1970-01-01',
            now()->toDateString()
        );
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;
        if ($request->user()->role === 'branch_manager') {
            $branchId = $request->user()->branch_id ? (int) $request->user()->branch_id : $branchId;
        }
        $currency = $request->get('currency');
        if ($currency && !in_array($currency, ['toman', 'dinar'], true)) {
            $currency = null;
        }

        return response()->json([
            'invoices' => $service->invoiceRows($request->user(), $branchId, $period['from'], $period['to'], $currency),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $apply = false): array
    {
        $rules = [
            'scope' => 'nullable|in:all_branches,selected_branches',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer|exists:branches,id',
            'rate' => 'required',
            'effective_from' => 'required|date',
            'reason' => 'required|string|max:255',
            'idempotency_key' => 'required|string|max:80',
        ];
        if ($apply) {
            $rules['preview_hash'] = 'required|string|size:64';
        }

        return $request->validate($rules);
    }
}
