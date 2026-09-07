<?php

namespace App\Services\Settlement;

use App\Exceptions\DomainException;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Ledger\FinancePostingGateway;
use App\Services\Suppliers\SupplierSettlementScope;
use App\Support\Authorization\BranchAccess;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettlementRecorder
{
    public function __construct(
        private readonly PeriodSettlement $period,
        private readonly FinancePostingGateway $gateway,
        private readonly SupplierSettlementScope $scope,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Settlement
    {
        $amount = Money::of($data['amount'] ?? 0);
        if (Money::cmp($amount, '0') <= 0) {
            throw new DomainException('مبلغ تسویه باید بزرگ‌تر از صفر باشد', 422);
        }
        if (!empty($data['aggregate'])) {
            throw new DomainException('حالت تجمیعی برای تسویه مجاز نیست', 422, ['error' => 'aggregate_not_allowed']);
        }

        $resolved = $this->scope->resolve($user, $data);
        $data['supplier_id'] = $resolved['supplier_id'];
        $supplierAccountId = $resolved['supplier_account_id'];
        $branchId = $resolved['branch_id'];

        $method = (string) $data['payment_method'];
        if ($method === 'check' && empty($data['check_number'])) {
            throw new DomainException('شماره چک برای تسویه با چک الزامی است', 422);
        }

        return DB::transaction(function () use ($user, $data, $amount, $branchId, $method, $supplierAccountId) {
            $allOpen = !empty($data['all_open']);
            $periodStart = $allOpen ? null : (string) $data['period_start'];
            $periodEnd = $allOpen ? null : (string) $data['period_end'];

            $preview = $this->period->preview(
                (int) $data['supplier_id'],
                (string) $data['currency'],
                $periodStart,
                $periodEnd,
                $branchId,
                $supplierAccountId
            );

            if (!empty($data['expected_total']) && Money::cmp((string) $data['expected_total'], $preview['total_payable']) !== 0) {
                throw new DomainException('مبلغ قابل پرداخت از زمان پیش‌نمایش تغییر کرده است', 409, [
                    'current_payable' => $preview['total_payable'],
                ]);
            }

            if (Money::cmp($amount, $preview['total_payable']) > 0) {
                throw new DomainException('مبلغ تسویه بیشتر از بدهی قابل پرداخت است', 422, [
                    'max_payable' => $preview['total_payable'],
                ]);
            }

            $settlement = Settlement::create([
                'supplier_id' => $data['supplier_id'],
                'supplier_account_id' => $supplierAccountId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'settlement_number' => 'SET-' . strtoupper(Str::random(8)),
                'period_type' => $data['period_type'],
                'period_start' => $preview['period_start'],
                'period_end' => $preview['period_end'],
                'amount' => $amount,
                'currency' => $data['currency'],
                'payment_method' => $method,
                'notes' => $data['notes'] ?? null,
                'check_number' => $data['check_number'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'check_status' => $method === 'check' ? 'pending' : null,
                'paid_at' => now(),
            ]);

            $this->period->settle(
                $settlement,
                $periodStart,
                $periodEnd,
                $amount,
                $preview['total_payable']
            );

            $this->gateway->supplierSettlement(
                $settlement->fresh(),
                isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null
            );

            return $settlement->fresh()->load(['supplier', 'branch', 'allocations']);
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $rows
     * @return list<Settlement>
     */
    public function createMany(User $user, array $header, array $rows): array
    {
        return DB::transaction(function () use ($user, $header, $rows) {
            $created = [];
            foreach ($rows as $row) {
                $created[] = $this->create($user, array_merge($header, $row));
            }

            return $created;
        });
    }

    /**
     * One admin confirm → one settlement per branch. Never a corporate (null branch) row.
     *
     * @param  array<string, mixed>  $data
     * @return list<Settlement>
     */
    public function createForAllBranches(User $user, array $data): array
    {
        BranchAccess::assertCanAggregateSupplierAccounts($user);

        if (!empty($data['aggregate'])) {
            unset($data['aggregate']);
        }

        $method = (string) ($data['payment_method'] ?? '');
        if ($method === 'check') {
            throw new DomainException('تسویه همه شعب با چک مجاز نیست', 422);
        }

        $supplierId = $this->canonicalSupplierId($data);
        $currency = (string) $data['currency'];
        $amount = Money::of($data['amount'] ?? 0);
        if (Money::cmp($amount, '0') <= 0) {
            throw new DomainException('مبلغ تسویه باید بزرگ‌تر از صفر باشد', 422);
        }

        return DB::transaction(function () use ($user, $data, $supplierId, $currency, $amount) {
            $allOpen = !empty($data['all_open']);
            $periodStart = $allOpen ? null : (string) $data['period_start'];
            $periodEnd = $allOpen ? null : (string) $data['period_end'];

            $preview = $this->period->previewAllBranches(
                $supplierId,
                $currency,
                $periodStart,
                $periodEnd
            );
            $rows = $preview['by_branch'] ?? [];
            if ($rows === []) {
                throw new DomainException('مانده‌ای برای تسویه همه شعب نیست', 422, [
                    'error' => 'no_branch_payable',
                ]);
            }

            if (!empty($data['expected_total']) && Money::cmp((string) $data['expected_total'], $preview['total_payable']) !== 0) {
                throw new DomainException('مبلغ قابل پرداخت از زمان پیش‌نمایش تغییر کرده است', 409, [
                    'current_payable' => $preview['total_payable'],
                ]);
            }

            if (Money::cmp($amount, $preview['total_payable']) > 0) {
                throw new DomainException('مبلغ تسویه بیشتر از بدهی قابل پرداخت است', 422, [
                    'max_payable' => $preview['total_payable'],
                ]);
            }

            $shares = $this->allocateByRemaining($rows, $amount);
            $created = [];
            foreach ($shares as $share) {
                $created[] = $this->create($user, [
                    'supplier_account_id' => $share['supplier_account_id'],
                    'branch_id' => $share['branch_id'],
                    'period_type' => $data['period_type'],
                    'period_start' => $preview['period_start'],
                    'period_end' => $preview['period_end'],
                    'all_open' => $allOpen,
                    'amount' => $share['amount'],
                    'expected_total' => $share['remaining_payable'],
                    'currency' => $currency,
                    'payment_method' => $data['payment_method'],
                    'notes' => $data['notes'] ?? null,
                ]);
            }

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function canonicalSupplierId(array $data): int
    {
        if (!empty($data['supplier_id'])) {
            return (int) $data['supplier_id'];
        }
        if (!empty($data['supplier_account_id'])) {
            $account = \App\Models\SupplierAccount::query()->find($data['supplier_account_id']);
            $supplierId = $account?->supplier_id ? (int) $account->supplier_id : 0;
            if ($supplierId > 0) {
                return $supplierId;
            }
        }

        throw new DomainException('برای تسویه همه شعب، تأمین‌کننده متعارف لازم است', 422);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{supplier_account_id: int, branch_id: int, remaining_payable: string, amount: string}>
     */
    private function allocateByRemaining(array $rows, string $amount): array
    {
        $total = '0.00';
        foreach ($rows as $row) {
            $total = Money::add($total, $row['remaining_payable'] ?? 0);
        }

        $shares = [];
        $paid = '0.00';
        $last = count($rows) - 1;
        foreach ($rows as $index => $row) {
            $remaining = Money::of($row['remaining_payable'] ?? 0);
            if ($index === $last) {
                $share = Money::min($remaining, Money::sub($amount, $paid));
            } elseif (Money::isZero($total)) {
                $share = '0.00';
            } else {
                $ratio = bcdiv($remaining, $total, 8);
                $share = Money::min($remaining, Money::roundHalfUp(bcmul($amount, $ratio, 8)));
            }
            if (Money::cmp($share, '0') <= 0) {
                continue;
            }
            $shares[] = [
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'branch_id' => (int) $row['branch_id'],
                'remaining_payable' => $remaining,
                'amount' => Money::of($share),
            ];
            $paid = Money::add($paid, $share);
        }

        if ($shares === []) {
            throw new DomainException('مانده‌ای برای تسویه همه شعب نیست', 422, [
                'error' => 'no_branch_payable',
            ]);
        }

        return $shares;
    }
}
