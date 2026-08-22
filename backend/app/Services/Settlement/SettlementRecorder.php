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
            $preview = $this->period->preview(
                (int) $data['supplier_id'],
                (string) $data['currency'],
                (string) $data['period_start'],
                (string) $data['period_end'],
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
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
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
                (string) $data['period_start'],
                (string) $data['period_end'],
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
}
