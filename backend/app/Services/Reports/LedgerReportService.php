<?php

namespace App\Services\Reports;

use App\Models\Book;
use App\Models\Branch;
use App\Models\Check;
use App\Models\Customer;
use App\Models\CustomerReturnItem;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LedgerAccount;
use App\Models\Settlement;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Services\Ledger\CheckLifecycle;
use App\Services\Ledger\SystemAccounts;
use App\Services\Receivables\CustomerBalances;
use App\Services\Receivables\InvoiceBalance;
use App\Services\Settlement\SnapshotPayable;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LedgerReportService
{
    public function __construct(
        private readonly PostedJournals $journals = new PostedJournals(),
        private readonly InvoiceBalance $invoiceBalance = new InvoiceBalance(),
        private readonly CustomerBalances $customerBalances = new CustomerBalances(new InvoiceBalance()),
        private readonly SnapshotPayable $payables = new SnapshotPayable(),
    ) {
    }

    /**
     * @return array{from: string, to: string, start: Carbon, end: Carbon}
     */
    public function period(?string $from, ?string $to, ?string $defaultFrom = null, ?string $defaultTo = null): array
    {
        return $this->journals->period($from, $to, $defaultFrom, $defaultTo);
    }

    public function currency(?string $currency, string $default = 'toman'): string
    {
        return $this->journals->currency($currency, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function pnl(?int $branchId, string $dateFrom, string $dateTo, string $currency): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $block = $this->pnlBlock($branchId, $period['start'], $period['end'], $currency);

        return [
            'branch_id' => $branchId,
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'currency' => $currency,
            ...$block,
            'equation' => 'net_profit = (sales_revenue - sales_returns) - net_cogs - operating_expenses - gift_expenses',
            'journal_inclusion' => 'posted originals and reversals; draft/void excluded',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function pnlCurrencies(?int $branchId, string $dateFrom, string $dateTo): array
    {
        $out = [];
        foreach (['toman', 'dinar'] as $currency) {
            $out[$currency] = $this->pnl($branchId, $dateFrom, $dateTo, $currency);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allBranchPnls(string $dateFrom, string $dateTo): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $branches = Branch::query()->orderBy('id')->get();
        $rows = [];
        foreach ($branches as $branch) {
            $currencies = [];
            $aliases = [];
            foreach (['toman', 'dinar'] as $currency) {
                $block = $this->pnlBlock((int) $branch->id, $period['start'], $period['end'], $currency);
                $currencies[$currency] = $this->frontendPnlShape($block);
                $ar = $this->accountNet(
                    SystemAccounts::get('accounts_receivable', $currency)->id,
                    (int) $branch->id,
                    null,
                    $period['end'],
                    $currency,
                    'asset'
                );
                $aliases['revenue_'.$currency] = $block['net_sales'];
                $aliases['cogs_'.$currency] = $block['net_cogs'];
                $aliases['expenses_'.$currency] = $block['operating_expenses'];
                $aliases['gift_costs_'.$currency] = $block['gift_expenses'];
                $aliases['net_profit_'.$currency] = $block['net_profit'];
                $aliases['pending_credit_'.$currency] = $ar['balance'];
            }
            $rows[] = [
                'branch' => $branch,
                'period' => ['from' => $period['from'], 'to' => $period['to']],
                'currencies' => $currencies,
                ...$aliases,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function branchProfit(Branch $branch, string $dateFrom, string $dateTo): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $currencies = [];
        $aliases = [];
        foreach (['toman', 'dinar'] as $currency) {
            $block = $this->pnlBlock((int) $branch->id, $period['start'], $period['end'], $currency);
            $currencies[$currency] = $this->frontendPnlShape($block);
            $aliases['revenue_'.$currency] = $block['net_sales'];
            $aliases['cogs_'.$currency] = $block['net_cogs'];
            $aliases['expenses_'.$currency] = $block['operating_expenses'];
            $aliases['gift_costs_'.$currency] = $block['gift_expenses'];
            $aliases['net_profit_'.$currency] = $block['net_profit'];
        }

        return [
            'branch' => $branch,
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'currencies' => $currencies,
            ...$aliases,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function treasury(?int $branchId, string $dateFrom, string $dateTo, bool $includeCorporate): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $accounts = FinancialAccount::query()
            ->with('ledgerAccount')
            ->orderBy('id')
            ->get();
        $rows = [];
        foreach ($accounts as $account) {
            $isCorporate = $account->branch_id === null;
            if ($isCorporate && !$includeCorporate) {
                continue;
            }
            if ($branchId !== null && (int) $account->branch_id !== $branchId) {
                continue;
            }
            if ($branchId === null && !$includeCorporate && $account->branch_id === null) {
                continue;
            }
            $opening = $this->financialAccountSums((int) $account->id, null, $period['start']->copy()->subSecond());
            $periodSums = $this->financialAccountSums((int) $account->id, $period['start'], $period['end']);
            $closing = $this->financialAccountSums((int) $account->id, null, $period['end']);
            $type = (string) $account->type;
            $nature = $this->treasuryNormalBalance($type);
            $balanceFn = $nature === 'liability'
                ? fn (array $s) => $this->journals->liabilityBalance($s['debit'], $s['credit'])
                : fn (array $s) => $this->journals->assetBalance($s['debit'], $s['credit']);
            $rows[] = [
                'financial_account_id' => $account->id,
                'branch_id' => $account->branch_id,
                'name' => $account->name,
                'code' => $account->code,
                'type' => $type,
                'currency' => $account->currency,
                'debit' => $closing['debit'],
                'credit' => $closing['credit'],
                'balance' => $balanceFn($closing),
                'opening_balance' => $balanceFn($opening),
                'period_debit' => $periodSums['debit'],
                'period_credit' => $periodSums['credit'],
                'closing_balance' => $balanceFn($closing),
            ];
        }

        return [
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'accounts' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function trialBalance(?int $branchId, string $dateFrom, string $dateTo, string $currency): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $accounts = LedgerAccount::query()->orderBy('code')->get();
        $rows = [];
        $periodDebit = '0.00';
        $periodCredit = '0.00';
        $closingDebit = '0.00';
        $closingCredit = '0.00';
        foreach ($accounts as $account) {
            $opening = $this->accountRaw((int) $account->id, $branchId, null, $period['start']->copy()->subSecond(), $currency);
            $periodSums = $this->accountRaw((int) $account->id, $branchId, $period['start'], $period['end'], $currency);
            $closing = $this->accountRaw((int) $account->id, $branchId, null, $period['end'], $currency);
            if (Money::isZero($opening['debit']) && Money::isZero($opening['credit'])
                && Money::isZero($periodSums['debit']) && Money::isZero($periodSums['credit'])
                && Money::isZero($closing['debit']) && Money::isZero($closing['credit'])) {
                continue;
            }
            $openingNet = Money::sub($opening['debit'], $opening['credit']);
            $closingNet = Money::sub($closing['debit'], $closing['credit']);
            $rows[] = [
                'ledger_account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'branch_id' => $branchId,
                'currency' => $currency,
                'opening_debit' => $opening['debit'],
                'opening_credit' => $opening['credit'],
                'opening_net' => $openingNet,
                'period_debit' => $periodSums['debit'],
                'period_credit' => $periodSums['credit'],
                'closing_debit' => $closing['debit'],
                'closing_credit' => $closing['credit'],
                'closing_net' => $closingNet,
            ];
            $periodDebit = Money::add($periodDebit, $periodSums['debit']);
            $periodCredit = Money::add($periodCredit, $periodSums['credit']);
            $closingDebit = Money::add($closingDebit, $closing['debit']);
            $closingCredit = Money::add($closingCredit, $closing['credit']);
        }

        return [
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'currency' => $currency,
            'branch_id' => $branchId,
            'accounts' => $rows,
            'total_debits' => $periodDebit,
            'total_credits' => $periodCredit,
            'closing_total_debits' => $closingDebit,
            'closing_total_credits' => $closingCredit,
            'balanced' => Money::cmp($periodDebit, $periodCredit) === 0
                && Money::cmp($closingDebit, $closingCredit) === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function financialPosition(?int $branchId, string $dateFrom, string $dateTo, string $currency): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $end = $period['end'];
        $beforePeriod = $period['start']->copy()->subSecond();
        $asset = function (string $code) use ($branchId, $end, $currency) {
            return $this->accountNet(SystemAccounts::get($code, $currency)->id, $branchId, null, $end, $currency, 'asset')['balance'];
        };
        $liability = function (string $code) use ($branchId, $end, $currency) {
            return $this->accountNet(SystemAccounts::get($code, $currency)->id, $branchId, null, $end, $currency, 'liability')['balance'];
        };
        $accumulated = $this->pnlBlock(
            $branchId,
            Carbon::parse('1970-01-01', config('app.timezone'))->startOfDay(),
            $beforePeriod,
            $currency
        )['net_profit'];
        $current = $this->pnlBlock($branchId, $period['start'], $end, $currency)['net_profit'];
        $assets = [
            'cash_drawers' => $asset('cash_drawer'),
            'banks' => $asset('bank'),
            'card_clearing' => $asset('card_clearing'),
            'checks_receivable' => $asset('checks_receivable'),
            'accounts_receivable' => $asset('accounts_receivable'),
            'owned_inventory' => $asset('owned_inventory'),
            'supplier_recoverable' => $asset('supplier_recoverable'),
        ];
        $liabilities = [
            'supplier_payable' => $liability('supplier_payable'),
            'checks_payable' => $liability('checks_payable'),
            'trade_payable' => $liability('trade_payable'),
            'customer_credit_liability' => $liability('customer_credit_liability'),
        ];
        $totalAssets = $this->sumMoney(array_values($assets));
        $totalLiabilities = $this->sumMoney(array_values($liabilities));
        $totalEquity = Money::add($accumulated, $current);
        $totalLiabilitiesAndEquity = Money::add($totalLiabilities, $totalEquity);
        $difference = Money::sub($totalAssets, $totalLiabilitiesAndEquity);

        return [
            'as_of' => $period['to'],
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'currency' => $currency,
            'branch_id' => $branchId,
            'assets' => $assets,
            'total_assets' => $totalAssets,
            'liabilities' => $liabilities,
            'total_liabilities' => $totalLiabilities,
            'equity' => [
                'accumulated_result_before_period' => $accumulated,
                'current_period_result' => $current,
                'total_equity' => $totalEquity,
                'period_result' => $current,
                'accumulated_result' => $accumulated,
            ],
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'difference' => $difference,
            'balanced' => Money::cmp($difference, '0') === 0,
            'equation' => 'total_assets = total_liabilities + total_equity',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function receivables(?int $branchId, string $dateFrom, string $dateTo, string $currency): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $openingAt = $period['start']->copy()->subSecond();
        $closingAt = $period['end'];
        $accountId = SystemAccounts::get('accounts_receivable', $currency)->id;
        $creditId = SystemAccounts::get('customer_credit_liability', $currency)->id;
        $openingLedger = $this->accountNet($accountId, $branchId, null, $openingAt, $currency, 'asset')['balance'];
        $closingLedger = $this->accountNet($accountId, $branchId, null, $closingAt, $currency, 'asset')['balance'];
        $openingOperational = $this->operationalReceivableTotal($branchId, $currency, $openingAt);
        $closingOperational = $this->operationalReceivableTotal($branchId, $currency, $closingAt);

        $grouped = $this->journals->grouped(
            $this->scoped($currency, $branchId, null, $closingAt)->where('journal_lines.ledger_account_id', $accountId),
            ['journal_lines.customer_id']
        );
        $creditGrouped = $this->journals->grouped(
            $this->scoped($currency, $branchId, null, $closingAt)->where('journal_lines.ledger_account_id', $creditId),
            ['journal_lines.customer_id']
        );
        $creditByCustomer = [];
        foreach ($creditGrouped as $row) {
            $cid = $row->customer_id !== null ? (int) $row->customer_id : 0;
            $creditByCustomer[$cid] = $this->journals->liabilityBalance(Money::of($row->debit), Money::of($row->credit));
        }
        $customerNames = Customer::query()
            ->whereIn('id', collect($grouped)->pluck('customer_id')->merge(array_keys($creditByCustomer))->filter()->unique())
            ->pluck('name', 'id');
        $customers = [];
        $seen = [];
        foreach ($grouped as $row) {
            $cid = $row->customer_id !== null ? (int) $row->customer_id : 0;
            $seen[$cid] = true;
            $ledgerAr = $this->journals->assetBalance(Money::of($row->debit), Money::of($row->credit));
            $customers[] = $this->receivableRow(
                $cid,
                $currency,
                $branchId,
                $ledgerAr,
                $creditByCustomer[$cid] ?? '0.00',
                $closingAt,
                $customerNames[$cid] ?? null
            );
        }
        foreach ($creditByCustomer as $cid => $credit) {
            if (isset($seen[$cid])) {
                continue;
            }
            $customers[] = $this->receivableRow(
                (int) $cid,
                $currency,
                $branchId,
                '0.00',
                $credit,
                $closingAt,
                $customerNames[$cid] ?? null
            );
        }

        $difference = Money::sub($closingLedger, $closingOperational);

        return [
            'as_of' => $period['to'],
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'currency' => $currency,
            'branch_id' => $branchId,
            'opening' => [
                'ledger' => $openingLedger,
                'operational' => $openingOperational,
            ],
            'period_movement' => [
                'ledger' => Money::sub($closingLedger, $openingLedger),
                'operational' => Money::sub($closingOperational, $openingOperational),
            ],
            'closing' => [
                'ledger' => $closingLedger,
                'operational' => $closingOperational,
            ],
            'customers' => $customers,
            'ledger_total' => $closingLedger,
            'operational_total' => $closingOperational,
            'operational_as_of' => $closingOperational,
            'difference' => $difference,
            'reconciled' => Money::cmp($difference, '0') === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payables(?int $branchId, string $dateFrom, string $dateTo, string $currency): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $openingAt = $period['start']->copy()->subSecond();
        $end = $period['end'];
        $payableId = SystemAccounts::get('supplier_payable', $currency)->id;
        $checksId = SystemAccounts::get('checks_payable', $currency)->id;
        $recoverableId = SystemAccounts::get('supplier_recoverable', $currency)->id;
        $openingLedger = $this->accountNet($payableId, $branchId, null, $openingAt, $currency, 'liability')['balance'];
        $closingLedger = $this->accountNet($payableId, $branchId, null, $end, $currency, 'liability')['balance'];
        $openingOperational = $this->operationalPayableTotal($branchId, $currency, $openingAt);
        $closingOperational = $this->operationalPayableTotal($branchId, $currency, $end);
        $grouped = $this->journals->grouped(
            $this->scoped($currency, $branchId, null, $end)->where('journal_lines.ledger_account_id', $payableId),
            ['journal_lines.supplier_id', 'journal_lines.branch_id']
        );
        $supplierNames = Supplier::query()
            ->whereIn('id', collect($grouped)->pluck('supplier_id')->filter()->unique())
            ->pluck('name', 'id');
        $branchNames = Branch::query()
            ->whereIn('id', collect($grouped)->pluck('branch_id')->filter()->unique())
            ->pluck('name', 'id');
        $suppliers = [];
        foreach ($grouped as $row) {
            $supplierId = $row->supplier_id !== null ? (int) $row->supplier_id : 0;
            $lineBranch = $row->branch_id !== null ? (int) $row->branch_id : null;
            $payable = $this->journals->liabilityBalance(Money::of($row->debit), Money::of($row->credit));
            $checks = $this->partyAccountNet($checksId, $supplierId, $lineBranch, $currency, $end, 'liability', 'supplier_id');
            $recoverable = $this->partyAccountNet($recoverableId, $supplierId, $lineBranch, $currency, $end, 'asset', 'supplier_id');
            $operational = $this->payables->operationalSupplierOpenAsOf($supplierId, $currency, $lineBranch, $end);
            $suppliers[] = [
                'supplier_id' => $supplierId ?: null,
                'supplier_name' => $supplierId ? ($supplierNames[$supplierId] ?? null) : null,
                'branch_id' => $lineBranch,
                'branch_name' => $lineBranch ? ($branchNames[$lineBranch] ?? null) : null,
                'currency' => $currency,
                'supplier_payable' => $payable,
                'checks_payable' => $checks,
                'supplier_recoverable' => $recoverable,
                'operational_payable' => $operational,
                'operational_as_of' => $operational,
                'difference' => Money::sub($payable, $operational),
            ];
        }
        $difference = Money::sub($closingLedger, $closingOperational);

        return [
            'as_of' => $period['to'],
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'currency' => $currency,
            'branch_id' => $branchId,
            'opening' => [
                'ledger' => $openingLedger,
                'operational' => $openingOperational,
            ],
            'period_movement' => [
                'ledger' => Money::sub($closingLedger, $openingLedger),
                'operational' => Money::sub($closingOperational, $openingOperational),
            ],
            'closing' => [
                'ledger' => $closingLedger,
                'operational' => $closingOperational,
            ],
            'suppliers' => $suppliers,
            'ledger_total' => $closingLedger,
            'operational_total' => $closingOperational,
            'operational_as_of' => $closingOperational,
            'difference' => $difference,
            'reconciled' => Money::cmp($difference, '0') === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checks(?int $branchId, string $dateFrom, string $dateTo, string $currency): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $openingAt = $period['start']->copy()->subSecond();
        $closingAt = $period['end'];
        $historical = $this->checkBuckets($branchId, $currency, $closingAt);
        $opening = $this->checkBuckets($branchId, $currency, $openingAt);
        $current = $this->checkBuckets($branchId, $currency, now(), useCurrentStatus: true);
        $recvOpening = $this->accountNet(
            SystemAccounts::get('checks_receivable', $currency)->id,
            $branchId,
            null,
            $openingAt,
            $currency,
            'asset'
        )['balance'];
        $payOpening = $this->accountNet(
            SystemAccounts::get('checks_payable', $currency)->id,
            $branchId,
            null,
            $openingAt,
            $currency,
            'liability'
        )['balance'];
        $recv = $this->accountNet(
            SystemAccounts::get('checks_receivable', $currency)->id,
            $branchId,
            null,
            $closingAt,
            $currency,
            'asset'
        )['balance'];
        $pay = $this->accountNet(
            SystemAccounts::get('checks_payable', $currency)->id,
            $branchId,
            null,
            $closingAt,
            $currency,
            'liability'
        )['balance'];
        $incomingFace = $this->sumMoney([
            $historical['incoming']['pending'],
            $historical['incoming']['cleared'],
            $historical['incoming']['bounced'],
        ]);
        $outgoingFace = $this->sumMoney([
            $historical['outgoing']['pending'],
            $historical['outgoing']['cleared'],
            $historical['outgoing']['bounced'],
            $historical['outgoing']['cancelled'],
        ]);
        $incomingDiff = Money::sub($recv, $historical['incoming']['pending']);
        $outgoingDiff = Money::sub($pay, $historical['outgoing']['pending']);

        return [
            'as_of' => $period['to'],
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'currency' => $currency,
            'branch_id' => $branchId,
            'opening' => [
                'incoming_pending' => $opening['incoming']['pending'],
                'outgoing_pending' => $opening['outgoing']['pending'],
                'ledger_checks_receivable' => $recvOpening,
                'ledger_checks_payable' => $payOpening,
            ],
            'period_movement' => [
                'incoming_pending' => Money::sub($historical['incoming']['pending'], $opening['incoming']['pending']),
                'outgoing_pending' => Money::sub($historical['outgoing']['pending'], $opening['outgoing']['pending']),
                'ledger_checks_receivable' => Money::sub($recv, $recvOpening),
                'ledger_checks_payable' => Money::sub($pay, $payOpening),
            ],
            'closing' => [
                'incoming' => $historical['incoming'],
                'outgoing' => $historical['outgoing'],
                'ledger_checks_receivable' => $recv,
                'ledger_checks_payable' => $pay,
                'incoming_pending' => $historical['incoming']['pending'],
                'outgoing_pending' => $historical['outgoing']['pending'],
            ],
            'incoming' => $historical['incoming'],
            'outgoing' => $historical['outgoing'],
            'incoming_historical' => $historical['incoming'],
            'outgoing_historical' => $historical['outgoing'],
            'incoming_current' => $current['incoming'],
            'outgoing_current' => $current['outgoing'],
            'ledger_checks_receivable' => $recv,
            'ledger_checks_payable' => $pay,
            'incoming_operational_pending' => $historical['incoming']['pending'],
            'outgoing_operational_pending' => $historical['outgoing']['pending'],
            'operational_as_of' => [
                'incoming_pending' => $historical['incoming']['pending'],
                'outgoing_pending' => $historical['outgoing']['pending'],
            ],
            'incoming_difference' => $incomingDiff,
            'outgoing_difference' => $outgoingDiff,
            'difference' => $this->sumMoney([$incomingDiff, $outgoingDiff]),
            'incoming_face_amount' => $incomingFace,
            'outgoing_face_amount' => $outgoingFace,
            'reconciled' => Money::cmp($incomingDiff, '0') === 0 && Money::cmp($outgoingDiff, '0') === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventoryValue(?int $branchId, string $currency): array
    {
        $lotsQuery = StockLot::query()
            ->with(['book:id,title', 'branch:id,name'])
            ->where('currency', $currency)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $ownedLots = [];
        $consignment = [];
        $lotOwned = '0.00';
        foreach ((clone $lotsQuery)->get() as $lot) {
            $qty = (string) (int) $lot->qty_available;
            $value = Money::mul($lot->unit_cost, $qty);
            $row = [
                'stock_lot_id' => $lot->id,
                'book_id' => $lot->book_id,
                'book_title' => $lot->book?->title,
                'branch_id' => $lot->branch_id,
                'branch_name' => $lot->branch?->name,
                'origin' => $lot->origin,
                'quantity' => $qty,
                'unit_cost' => Money::of($lot->unit_cost),
                'value' => $value,
                'ownership_type' => $lot->ownership_type,
            ];
            if ($lot->ownership_type === 'owned') {
                $ownedLots[] = $row;
                $lotOwned = Money::add($lotOwned, $value);
            } else {
                $consignment[] = [
                    'stock_lot_id' => $lot->id,
                    'book_id' => $lot->book_id,
                    'book_title' => $lot->book?->title,
                    'branch_id' => $lot->branch_id,
                    'branch_name' => $lot->branch?->name,
                    'quantity' => $qty,
                    'memorandum_only' => true,
                ];
            }
        }
        $ledger = $this->accountNet(
            SystemAccounts::get('owned_inventory', $currency)->id,
            $branchId,
            null,
            now()->endOfDay(),
            $currency,
            'asset'
        )['balance'];
        $difference = Money::sub($ledger, $lotOwned);

        return [
            'currency' => $currency,
            'branch_id' => $branchId,
            'ledger_owned_inventory' => $ledger,
            'stock_lot_owned_inventory' => $lotOwned,
            'difference' => $difference,
            'reconciled' => Money::cmp($difference, '0') === 0,
            'owned_lots' => $ownedLots,
            'consignment_memorandum' => $consignment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function iraqProfit(string $dateFrom, string $dateTo, string $currency): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $iraqIds = Branch::query()
            ->where(function ($q) {
                $q->where('country', 'عراق')->orWhere('is_iraq_store', true);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $byCurrency = [];
        foreach (['toman', 'dinar'] as $cur) {
            $byCurrency[$cur] = $this->iraqCurrencyBlock($iraqIds, $period['start'], $period['end'], $cur);
        }
        $iraqLocal = $byCurrency[$currency]['iraq_local'];
        $qom = $byCurrency[$currency]['qom_distributed'];
        $shared = $byCurrency[$currency]['shared'];
        $combined = $byCurrency[$currency]['combined'];
        $iraqBranches = Branch::query()->whereIn('id', $iraqIds ?: [0])->get();

        return [
            'branch' => $iraqBranches->first(),
            'branches' => $iraqBranches,
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'currency' => $currency,
            'iraq_local' => $iraqLocal,
            'qom_distributed' => $qom,
            'shared' => $shared,
            'combined' => $combined,
            'currencies' => $byCurrency,
            'revenue' => $combined['net_sales'],
            'expenses' => $combined['operating_expenses'],
            'cogs' => $combined['cogs'],
            'gifts' => $combined['gift_expenses'],
            'returns' => $combined['sales_returns'],
            'net_profit' => $combined['net_profit'],
            'sales_count' => $iraqLocal['sales_count'] + $qom['sales_count'],
            'iraq_only_revenue' => $iraqLocal['net_sales'],
            'distributed_revenue' => $qom['net_sales'],
            'total_iraq_revenue' => $combined['net_sales'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardFinancials(?int $branchId, Carbon $day): array
    {
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();
        $out = [
            'inventory_value_basis' => 'owned_inventory_at_cost',
        ];
        foreach (['toman', 'dinar'] as $currency) {
            $block = $this->pnlBlock($branchId, $start, $end, $currency);
            $out['today_gross_sales_'.$currency] = $block['sales_revenue'];
            $out['today_returns_'.$currency] = $block['sales_returns'];
            $out['today_sales_'.$currency] = $block['net_sales'];
            $inv = $this->inventoryValue($branchId, $currency);
            $out['inventory_value_'.$currency] = $inv['stock_lot_owned_inventory'];
            $out['owned_inventory_at_cost_'.$currency] = $inv['stock_lot_owned_inventory'];
            $out['owned_inventory_ledger_'.$currency] = $inv['ledger_owned_inventory'];
            $out['owned_inventory_difference_'.$currency] = $inv['difference'];
        }
        $invoiceCount = Invoice::query()
            ->whereBetween('sold_at', [$start, $end])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();
        $out['today_invoice_count'] = $invoiceCount;

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function monthlyTrends(?int $branchId, string $currency, int $months): array
    {
        $months = min(12, max(3, $months));
        $data = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now()->subMonths($i)->startOfMonth();
            $end = now()->subMonths($i)->endOfMonth();
            $block = $this->pnlBlock($branchId, $start, $end, $currency);
            $data[] = [
                'label' => $start->format('Y-m'),
                'month' => (int) $start->format('n'),
                'currency' => $currency,
                'gross_sales' => $block['sales_revenue'],
                'sales_returns' => $block['sales_returns'],
                'net_sales' => $block['net_sales'],
                'cogs' => $block['net_cogs'],
                'gross_profit' => $block['gross_profit'],
                'expenses' => $block['operating_expenses'],
                'gifts' => $block['gift_expenses'],
                'net_profit' => $block['net_profit'],
                'sales' => $block['net_sales'],
                'profit' => $block['net_profit'],
            ];
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topBooks(?int $branchId, string $dateFrom, string $dateTo, ?string $currency = null): array
    {
        $period = $this->journals->period($dateFrom, $dateTo);
        $tz = (string) config('app.timezone', 'UTC');
        $sales = InvoiceItem::query()
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('books', 'invoice_items.book_id', '=', 'books.id')
            ->whereBetween('invoices.sold_at', [$period['start'], $period['end']])
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
            ->when($currency, fn ($q) => $q->where('invoices.currency', $currency))
            ->get([
                'invoice_items.book_id',
                'books.title',
                'books.author',
                'invoices.currency',
                'invoice_items.quantity',
                'invoice_items.actual_price',
                'invoice_items.discount',
            ]);
        $buckets = [];
        foreach ($sales as $row) {
            $cur = (string) $row->currency;
            $key = $row->book_id.'|'.$cur;
            $unit = Money::sub($row->actual_price ?? 0, $row->discount ?? 0);
            $rev = Money::mul($unit, (int) $row->quantity);
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'book_id' => (int) $row->book_id,
                    'title' => $row->title,
                    'author' => $row->author,
                    'currency' => $cur,
                    'total_sold' => 0,
                    'total_revenue' => '0.00',
                ];
            }
            $buckets[$key]['total_sold'] += (int) $row->quantity;
            $buckets[$key]['total_revenue'] = Money::add($buckets[$key]['total_revenue'], $rev);
        }
        $returns = CustomerReturnItem::query()
            ->join('customer_returns', 'customer_return_items.customer_return_id', '=', 'customer_returns.id')
            ->join('invoice_items', 'customer_return_items.invoice_item_id', '=', 'invoice_items.id')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('books', 'invoice_items.book_id', '=', 'books.id')
            ->whereBetween('customer_returns.returned_at', [$period['start'], $period['end']])
            ->when($branchId, fn ($q) => $q->where('customer_returns.branch_id', $branchId))
            ->when($currency, fn ($q) => $q->where('invoices.currency', $currency))
            ->get([
                'invoice_items.book_id',
                'books.title',
                'books.author',
                'invoices.currency',
                'customer_return_items.quantity',
                'invoice_items.actual_price',
                'invoice_items.discount',
            ]);
        foreach ($returns as $row) {
            $cur = (string) $row->currency;
            $key = $row->book_id.'|'.$cur;
            $unit = Money::sub($row->actual_price ?? 0, $row->discount ?? 0);
            $rev = Money::mul($unit, (int) $row->quantity);
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'book_id' => (int) $row->book_id,
                    'title' => $row->title,
                    'author' => $row->author,
                    'currency' => $cur,
                    'total_sold' => 0,
                    'total_revenue' => '0.00',
                ];
            }
            $buckets[$key]['total_sold'] -= (int) $row->quantity;
            $buckets[$key]['total_revenue'] = Money::sub($buckets[$key]['total_revenue'], $rev);
        }
        $list = array_values($buckets);
        usort($list, fn ($a, $b) => $b['total_sold'] <=> $a['total_sold']);

        return array_slice($list, 0, 10);
    }

    /**
     * @return array<string, string>
     */
    public function pnlBlock(?int $branchId, Carbon $start, Carbon $end, string $currency, ?string $origin = null): array
    {
        $revenue = $this->sysNet('sales_revenue', $branchId, $start, $end, $currency, 'revenue', $origin);
        $returns = $this->sysNet('sales_returns', $branchId, $start, $end, $currency, 'expense', $origin);
        $cogs = $this->sysNet('cogs', $branchId, $start, $end, $currency, 'expense', $origin);
        $opex = $this->sysNet('operating_expense', $branchId, $start, $end, $currency, 'expense', $origin);
        $gifts = $this->sysNet('gift_expense', $branchId, $start, $end, $currency, 'expense', $origin);
        $netSales = Money::sub($revenue, $returns);
        $gross = Money::sub($netSales, $cogs);
        $net = Money::sub(Money::sub($gross, $opex), $gifts);

        return [
            'sales_revenue' => $revenue,
            'gross_sales' => $revenue,
            'sales_returns' => $returns,
            'net_sales' => $netSales,
            'net_cogs' => $cogs,
            'cogs' => $cogs,
            'gross_profit' => $gross,
            'operating_expenses' => $opex,
            'gift_expenses' => $gifts,
            'net_profit' => $net,
        ];
    }

    /**
     * @param  array<string, string>  $block
     * @return array<string, string>
     */
    public function frontendPnlShape(array $block): array
    {
        return [
            'gross_sales' => $block['sales_revenue'],
            'sales_returns' => $block['sales_returns'],
            'net_sales' => $block['net_sales'],
            'cogs' => $block['net_cogs'],
            'gross_profit' => $block['gross_profit'],
            'operating_expenses' => $block['operating_expenses'],
            'gift_expenses' => $block['gift_expenses'],
            'net_profit' => $block['net_profit'],
        ];
    }

    private function sysNet(
        string $code,
        ?int $branchId,
        Carbon $start,
        Carbon $end,
        string $currency,
        string $nature,
        ?string $origin
    ): string {
        $id = SystemAccounts::get($code, $currency)->id;
        $query = $this->scoped($currency, $branchId, $start, $end)
            ->where('journal_lines.ledger_account_id', $id);
        if ($origin !== null && $origin !== '') {
            $query->where('journal_lines.origin_scope', $origin);
        }
        $sums = $this->journals->sums($query);

        return $nature === 'revenue'
            ? $this->journals->revenueNet($sums['debit'], $sums['credit'])
            : $this->journals->expenseNet($sums['debit'], $sums['credit']);
    }

    /**
     * @return array{debit: string, credit: string, balance: string}
     */
    public function accountNet(
        int $accountId,
        ?int $branchId,
        ?Carbon $from,
        Carbon $to,
        string $currency,
        string $nature
    ): array {
        $raw = $this->accountRaw($accountId, $branchId, $from, $to, $currency);
        $balance = $nature === 'liability'
            ? $this->journals->liabilityBalance($raw['debit'], $raw['credit'])
            : $this->journals->assetBalance($raw['debit'], $raw['credit']);

        return $raw + ['balance' => $balance];
    }

    /**
     * @return array{debit: string, credit: string}
     */
    private function accountRaw(int $accountId, ?int $branchId, ?Carbon $from, Carbon $to, string $currency): array
    {
        $query = $this->journals->baseQuery()
            ->where('journal_lines.ledger_account_id', $accountId)
            ->where('journal_lines.currency', $currency);
        if ($branchId !== null) {
            $query->where('journal_lines.branch_id', $branchId);
        }
        if ($from) {
            $this->journals->applyOccurredAt($query, $from, $to, 'between');
        } else {
            $this->journals->applyOccurredAt($query, null, $to, 'through');
        }

        return $this->journals->sums($query);
    }

    private function scoped(string $currency, ?int $branchId, ?Carbon $from, Carbon $to, ?array $branchIds = null): Builder
    {
        $query = $this->journals->baseQuery();
        $this->journals->applyScope($query, $currency, $branchId, false, $branchIds);
        if ($from) {
            $this->journals->applyOccurredAt($query, $from, $to, 'between');
        } else {
            $this->journals->applyOccurredAt($query, null, $to, 'through');
        }

        return $query;
    }

    /**
     * @return array{debit: string, credit: string}
     */
    private function financialAccountSums(int $financialAccountId, ?Carbon $from, Carbon $to): array
    {
        $query = $this->journals->baseQuery()
            ->where('journal_lines.financial_account_id', $financialAccountId);
        if ($from) {
            $this->journals->applyOccurredAt($query, $from, $to, 'between');
        } else {
            $this->journals->applyOccurredAt($query, null, $to, 'through');
        }

        return $this->journals->sums($query);
    }

    /**
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */

    /**
     * @param  list<int>  $branchIds
     * @return array{iraq_local: array<string, mixed>, qom_distributed: array<string, mixed>, shared: array<string, mixed>, combined: array<string, mixed>}
     */
    private function iraqCurrencyBlock(array $branchIds, Carbon $start, Carbon $end, string $currency): array
    {
        $iraqLocal = $this->originPnl($branchIds, $start, $end, $currency, 'iraq_local');
        $qom = $this->originPnl($branchIds, $start, $end, $currency, 'qom_distributed');
        $sharedOpex = $this->originExpense($branchIds, $start, $end, $currency, 'shared');
        $iraqLocal['contribution_profit'] = Money::sub(
            Money::sub($iraqLocal['gross_profit'], $iraqLocal['gift_expenses']),
            $iraqLocal['operating_expenses']
        );
        $qom['contribution_profit'] = Money::sub(
            Money::sub($qom['gross_profit'], $qom['gift_expenses']),
            $qom['operating_expenses']
        );
        $shared = [
            'gross_sales' => '0.00',
            'sales_returns' => '0.00',
            'net_sales' => '0.00',
            'cogs' => '0.00',
            'gross_profit' => '0.00',
            'gift_expenses' => '0.00',
            'operating_expenses' => $sharedOpex,
            'contribution_profit' => Money::mul($sharedOpex, '-1'),
            'net_profit' => Money::mul($sharedOpex, '-1'),
        ];
        $combinedGross = Money::add($iraqLocal['gross_profit'], $qom['gross_profit']);
        $combinedGifts = Money::add($iraqLocal['gift_expenses'], $qom['gift_expenses']);
        $combinedOpex = Money::add(Money::add($iraqLocal['operating_expenses'], $qom['operating_expenses']), $sharedOpex);
        $combined = [
            'gross_sales' => Money::add($iraqLocal['gross_sales'], $qom['gross_sales']),
            'sales_returns' => Money::add($iraqLocal['sales_returns'], $qom['sales_returns']),
            'net_sales' => Money::add($iraqLocal['net_sales'], $qom['net_sales']),
            'cogs' => Money::add($iraqLocal['cogs'], $qom['cogs']),
            'gross_profit' => $combinedGross,
            'gift_expenses' => $combinedGifts,
            'operating_expenses' => $combinedOpex,
            'net_profit' => Money::sub(Money::sub($combinedGross, $combinedGifts), $combinedOpex),
            'contribution_profit' => Money::sub(
                Money::add($iraqLocal['contribution_profit'], $qom['contribution_profit']),
                $sharedOpex
            ),
        ];

        return [
            'iraq_local' => $iraqLocal,
            'qom_distributed' => $qom,
            'shared' => $shared,
            'combined' => $combined,
        ];
    }

    private function originPnl(array $branchIds, Carbon $start, Carbon $end, string $currency, string $origin): array
    {
        if ($branchIds === []) {
            return [
                'gross_sales' => '0.00',
                'sales_returns' => '0.00',
                'net_sales' => '0.00',
                'cogs' => '0.00',
                'gross_profit' => '0.00',
                'gift_expenses' => '0.00',
                'operating_expenses' => '0.00',
                'net_profit' => '0.00',
                'contribution_profit' => '0.00',
                'revenue' => '0.00',
                'gifts' => '0.00',
                'returns' => '0.00',
                'expenses' => '0.00',
                'sales_count' => 0,
            ];
        }
        $revenue = $this->originSys('sales_revenue', $branchIds, $start, $end, $currency, 'revenue', $origin);
        $returns = $this->originSys('sales_returns', $branchIds, $start, $end, $currency, 'expense', $origin);
        $cogs = $this->originSys('cogs', $branchIds, $start, $end, $currency, 'expense', $origin);
        $gifts = $this->originSys('gift_expense', $branchIds, $start, $end, $currency, 'expense', $origin);
        $opex = $this->originSys('operating_expense', $branchIds, $start, $end, $currency, 'expense', $origin);
        $netSales = Money::sub($revenue, $returns);
        $gross = Money::sub($netSales, $cogs);
        $net = Money::sub(Money::sub($gross, $opex), $gifts);
        $salesCount = (int) $this->journals->baseQuery()
            ->whereIn('journal_lines.branch_id', $branchIds)
            ->where('journal_lines.currency', $currency)
            ->where('journal_lines.origin_scope', $origin)
            ->where('journal_entries.event_type', 'sale')
            ->whereBetween('journal_entries.occurred_at', [$start, $end])
            ->distinct()
            ->count('journal_entries.source_id');

        return [
            'gross_sales' => $revenue,
            'sales_returns' => $returns,
            'net_sales' => $netSales,
            'cogs' => $cogs,
            'gross_profit' => $gross,
            'gift_expenses' => $gifts,
            'operating_expenses' => $opex,
            'net_profit' => $net,
            'contribution_profit' => Money::sub(Money::sub($gross, $gifts), $opex),
            'revenue' => $netSales,
            'gifts' => $gifts,
            'returns' => $returns,
            'expenses' => $opex,
            'sales_count' => $salesCount,
        ];
    }

    private function originSys(
        string $code,
        array $branchIds,
        Carbon $start,
        Carbon $end,
        string $currency,
        string $nature,
        string $origin
    ): string {
        $id = SystemAccounts::get($code, $currency)->id;
        $query = $this->journals->baseQuery()
            ->where('journal_lines.ledger_account_id', $id)
            ->where('journal_lines.currency', $currency)
            ->whereIn('journal_lines.branch_id', $branchIds)
            ->where('journal_lines.origin_scope', $origin);
        $this->journals->applyOccurredAt($query, $start, $end, 'between');
        $sums = $this->journals->sums($query);

        return $nature === 'revenue'
            ? $this->journals->revenueNet($sums['debit'], $sums['credit'])
            : $this->journals->expenseNet($sums['debit'], $sums['credit']);
    }

    private function originExpense(array $branchIds, Carbon $start, Carbon $end, string $currency, string $mode): string
    {
        if ($branchIds === []) {
            return '0.00';
        }
        $id = SystemAccounts::get('operating_expense', $currency)->id;
        $query = $this->journals->baseQuery()
            ->where('journal_lines.ledger_account_id', $id)
            ->where('journal_lines.currency', $currency)
            ->whereIn('journal_lines.branch_id', $branchIds);
        $this->journals->applyOccurredAt($query, $start, $end, 'between');
        if ($mode === 'shared') {
            $query->where(function ($q) {
                $q->whereNull('journal_lines.origin_scope')
                    ->orWhere('journal_lines.origin_scope', '')
                    ->orWhere('journal_lines.origin_scope', 'shared');
            });
        }
        $sums = $this->journals->sums($query);

        return $this->journals->expenseNet($sums['debit'], $sums['credit']);
    }

    /**
     * @return array<string, mixed>
     */
    private function receivableRow(
        int $customerId,
        string $currency,
        ?int $branchId,
        string $ledgerAr,
        string $credit,
        Carbon $asOf,
        ?string $customerName = null
    ): array
    {
        $operationalAr = '0.00';
        $overdue = '0.00';
        $drill = [];
        if ($customerId > 0) {
            $invoices = Invoice::query()
                ->with('check')
                ->where('customer_id', $customerId)
                ->where('currency', $currency)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->get();
            foreach ($invoices as $invoice) {
                if ($invoice->sold_at === null || $invoice->sold_at->gt($asOf)) {
                    continue;
                }
                $out = $this->invoiceBalance->outstandingAsOf($invoice, $asOf);
                $operationalAr = Money::add($operationalAr, $out);
                $historical = $this->invoiceBalance->statusAsOf($invoice, $asOf);
                if ($historical === 'overdue') {
                    $overdue = Money::add($overdue, $out);
                }
                $drill[] = [
                    'invoice_id' => $invoice->id,
                    'face_amount' => $this->invoiceBalance->original($invoice),
                    'outstanding' => $out,
                    'historical_status' => $historical,
                    'current_status' => (string) $invoice->payment_status,
                ];
            }
        }

        return [
            'customer_id' => $customerId ?: null,
            'customer_name' => $customerName,
            'currency' => $currency,
            'open_ar' => $ledgerAr,
            'overdue_ar' => $overdue,
            'customer_credit_liability' => $credit,
            'net_position' => Money::sub($ledgerAr, $credit),
            'operational_ar' => $operationalAr,
            'difference' => Money::sub($ledgerAr, $operationalAr),
            'invoices' => $drill,
        ];
    }

    private function operationalReceivableTotal(?int $branchId, string $currency, Carbon $asOf): string
    {
        $sum = '0.00';
        $invoices = Invoice::query()
            ->with('check')
            ->where('currency', $currency)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();
        foreach ($invoices as $invoice) {
            $sum = Money::add($sum, $this->invoiceBalance->outstandingAsOf($invoice, $asOf));
        }

        return $sum;
    }

    private function operationalPayableTotal(?int $branchId, string $currency, Carbon $asOf): string
    {
        $sum = '0.00';
        $supplierIds = \App\Models\SaleLotAllocation::query()
            ->join('stock_lots', 'stock_lots.id', '=', 'sale_lot_allocations.stock_lot_id')
            ->where('stock_lots.currency', $currency)
            ->when($branchId, fn ($q) => $q->where('stock_lots.branch_id', $branchId))
            ->whereNotNull('stock_lots.supplier_id')
            ->pluck('stock_lots.supplier_id')
            ->merge(
                \App\Models\GiftLotAllocation::query()
                    ->join('stock_lots', 'stock_lots.id', '=', 'gift_lot_allocations.stock_lot_id')
                    ->where('gift_lot_allocations.ownership_type', 'consignment')
                    ->where('stock_lots.currency', $currency)
                    ->when($branchId, fn ($q) => $q->where('stock_lots.branch_id', $branchId))
                    ->whereNotNull('stock_lots.supplier_id')
                    ->pluck('stock_lots.supplier_id')
            )
            ->unique()
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
        foreach ($supplierIds as $supplierId) {
            $sum = Money::add($sum, $this->payables->operationalSupplierOpenAsOf($supplierId, $currency, $branchId, $asOf));
        }

        return $sum;
    }

    /**
     * @return array{incoming: array<string, string>, outgoing: array<string, string>}
     */
    private function checkBuckets(?int $branchId, string $currency, Carbon $asOf, bool $useCurrentStatus = false): array
    {
        $incoming = ['pending' => '0.00', 'cleared' => '0.00', 'bounced' => '0.00', 'overdue' => '0.00'];
        $incomingQuery = Check::query()->with('invoice')->where('currency', $currency);
        if ($branchId) {
            $incomingQuery->where('branch_id', $branchId);
        }
        foreach ($incomingQuery->get() as $check) {
            $amount = Money::of($check->amount);
            $status = $useCurrentStatus
                ? (string) $check->status
                : CheckLifecycle::incomingStatusAsOf($check, $asOf);
            if ($status === null || !isset($incoming[$status])) {
                continue;
            }
            $incoming[$status] = Money::add($incoming[$status], $amount);
            $duePast = $check->due_date && $check->due_date->toDateString() < $asOf->toDateString();
            if ($status === 'pending' && $duePast) {
                $incoming['overdue'] = Money::add($incoming['overdue'], $amount);
            }
        }
        $outgoing = ['pending' => '0.00', 'cleared' => '0.00', 'bounced' => '0.00', 'cancelled' => '0.00', 'overdue' => '0.00'];
        $settlements = Settlement::query()
            ->where('currency', $currency)
            ->where('payment_method', 'check');
        if ($branchId) {
            $settlements->where('branch_id', $branchId);
        }
        foreach ($settlements->get() as $settlement) {
            $amount = Money::of($settlement->amount);
            $status = $useCurrentStatus
                ? (string) ($settlement->check_status ?? 'pending')
                : CheckLifecycle::supplierStatusAsOf($settlement, $asOf);
            if ($status === null || !isset($outgoing[$status])) {
                continue;
            }
            $outgoing[$status] = Money::add($outgoing[$status], $amount);
        }

        return ['incoming' => $incoming, 'outgoing' => $outgoing];
    }

    private function treasuryNormalBalance(string $type): string
    {
        return match ($type) {
            'checks_payable', 'supplier_payable' => 'liability',
            default => 'asset',
        };
    }

    /**
     * @param  list<string>  $values
     */
    private function sumMoney(array $values): string
    {
        $sum = '0.00';
        foreach ($values as $value) {
            $sum = Money::add($sum, $value);
        }

        return $sum;
    }

    private function partyAccountNet(
        int $accountId,
        int $partyId,
        ?int $branchId,
        string $currency,
        Carbon $to,
        string $nature,
        string $partyColumn
    ): string {
        $query = $this->journals->baseQuery()
            ->where('journal_lines.ledger_account_id', $accountId)
            ->where('journal_lines.currency', $currency)
            ->where('journal_lines.'.$partyColumn, $partyId ?: null);
        if ($branchId) {
            $query->where('journal_lines.branch_id', $branchId);
        }
        $this->journals->applyOccurredAt($query, null, $to, 'through');
        $sums = $this->journals->sums($query);

        return $nature === 'liability'
            ? $this->journals->liabilityBalance($sums['debit'], $sums['credit'])
            : $this->journals->assetBalance($sums['debit'], $sums['credit']);
    }
}
