<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\LedgerReportService;
use App\Support\Authorization\BranchAccess;
use Illuminate\Http\Request;

class FinanceReportController extends Controller
{
    public function __construct(private readonly LedgerReportService $reports)
    {
    }

    public function pnl(Request $request)
    {
        [$branchId, $from, $to, $currency] = $this->filters($request, requireCurrency: true);

        return response()->json($this->reports->pnl($branchId, $from, $to, $currency));
    }

    public function treasury(Request $request)
    {
        [$branchId, $from, $to] = $this->filters($request, requireCurrency: false);
        $includeCorporate = BranchAccess::canViewAllReports($request->user());

        return response()->json($this->reports->treasury($branchId, $from, $to, $includeCorporate));
    }

    public function trialBalance(Request $request)
    {
        [$branchId, $from, $to, $currency] = $this->filters($request, requireCurrency: true);

        return response()->json($this->reports->trialBalance($branchId, $from, $to, $currency));
    }

    public function financialPosition(Request $request)
    {
        [$branchId, $from, $to, $currency] = $this->filters($request, requireCurrency: true);

        return response()->json($this->reports->financialPosition($branchId, $from, $to, $currency));
    }

    public function receivables(Request $request)
    {
        [$branchId, $from, $to, $currency] = $this->filters($request, requireCurrency: true);

        return response()->json($this->reports->receivables($branchId, $from, $to, $currency));
    }

    public function payables(Request $request)
    {
        [$branchId, $from, $to, $currency] = $this->filters($request, requireCurrency: true);

        return response()->json($this->reports->payables($branchId, $from, $to, $currency));
    }

    public function checks(Request $request)
    {
        [$branchId, $from, $to, $currency] = $this->filters($request, requireCurrency: true);

        return response()->json($this->reports->checks($branchId, $from, $to, $currency));
    }

    public function inventoryValue(Request $request)
    {
        [$branchId, , , $currency] = $this->filters($request, requireCurrency: true);

        return response()->json($this->reports->inventoryValue($branchId, $currency));
    }

    /**
     * @return array{0: ?int, 1: string, 2: string, 3?: string}
     */
    private function filters(Request $request, bool $requireCurrency): array
    {
        BranchAccess::assertCanViewFinancialReports($request->user());
        $branchId = BranchAccess::resolveReportBranchId($request->user(), $request->input('branch_id'));
        $period = $this->reports->period(
            $request->input('date_from'),
            $request->input('date_to')
        );
        if ($requireCurrency) {
            $currency = $this->reports->currency($request->input('currency'));

            return [$branchId, $period['from'], $period['to'], $currency];
        }

        return [$branchId, $period['from'], $period['to']];
    }
}
