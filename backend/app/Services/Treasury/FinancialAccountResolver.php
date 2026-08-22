<?php

namespace App\Services\Treasury;

use App\Exceptions\DomainException;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Ledger\TreasuryScope;
use App\Support\Authorization\BranchAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class FinancialAccountResolver
{
    public static function scopeKey(?int $branchId, string $currency, string $type): string
    {
        $prefix = $branchId === null ? 'global' : 'branch:' . $branchId;

        return $prefix . ':' . $currency . ':' . $type;
    }

    public function default(?int $branchId, string $currency, string $type): FinancialAccount
    {
        $query = FinancialAccount::query()
            ->where('currency', $currency)
            ->where('type', $type)
            ->where('is_default', true)
            ->where('is_active', true)
            ->where('default_scope_key', self::scopeKey($branchId, $currency, $type));

        if ($branchId === null) {
            $query->whereNull('branch_id');
        } else {
            $query->where('branch_id', $branchId);
        }

        $account = $query->first();
        if (!$account) {
            throw new DomainException('حساب مالی پیش‌فرض برای این شعبه، ارز و نوع یافت نشد', 422, [
                'branch_id' => $branchId,
                'currency' => $currency,
                'type' => $type,
            ]);
        }

        return $account;
    }

    public function requireFor(
        string $eventType,
        string $paymentMethod,
        ?int $branchId,
        string $currency,
        ?int $selectedId,
        ?User $user
    ): FinancialAccount {
        $allowed = TreasuryScope::allowedTypes($eventType, $paymentMethod);
        if ($selectedId) {
            $account = FinancialAccount::query()->whereKey($selectedId)->first();
            if (!$account) {
                throw new DomainException('حساب مالی یافت نشد');
            }
            $this->assertVisible($account, $user);
            TreasuryScope::assert($account, $currency, $branchId, $eventType, $paymentMethod);

            return $account;
        }

        $missing = [];
        foreach ($allowed as $type) {
            try {
                $account = $this->default($branchId, $currency, $type);
                $this->assertVisible($account, $user);
                TreasuryScope::assert($account, $currency, $branchId, $eventType, $paymentMethod);

                return $account;
            } catch (DomainException $e) {
                $missing[] = $type;
            }
        }

        throw new DomainException('حساب مالی پیش‌فرض برای این شعبه، ارز و نوع یافت نشد', 422, [
            'branch_id' => $branchId,
            'currency' => $currency,
            'types' => $missing,
        ]);
    }

    private function assertVisible(FinancialAccount $account, ?User $user): void
    {
        $ids = BranchAccess::visibleBranchIds($user);
        if ($ids === null) {
            return;
        }
        if ($account->branch_id === null) {
            throw new DomainException('اجازه استفاده از حساب شرکتی را ندارید', 403);
        }
        if (!in_array((int) $account->branch_id, $ids, true)) {
            throw new DomainException('حساب مالی در شعبه مجاز شما نیست', 403);
        }
    }

    public function markDefault(FinancialAccount $account): FinancialAccount
    {
        if (!$account->is_active) {
            throw new DomainException('حساب غیرفعال نمی‌تواند پیش‌فرض باشد');
        }

        $scope = self::scopeKey(
            $account->branch_id !== null ? (int) $account->branch_id : null,
            $account->currency,
            $account->type
        );

        try {
            return DB::transaction(function () use ($account, $scope) {
                $q = FinancialAccount::query()
                    ->where('currency', $account->currency)
                    ->where('type', $account->type)
                    ->lockForUpdate();
                if ($account->branch_id === null) {
                    $q->whereNull('branch_id');
                } else {
                    $q->where('branch_id', $account->branch_id);
                }
                $q->get();

                FinancialAccount::query()
                    ->where('default_scope_key', $scope)
                    ->where('id', '!=', $account->id)
                    ->update([
                        'is_default' => false,
                        'default_scope_key' => null,
                    ]);

                $account->is_default = true;
                $account->is_active = true;
                $account->default_scope_key = $scope;
                $account->save();

                return $account->fresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new DomainException('حساب پیش‌فرض این محدوده هم‌اکنون توسط درخواست دیگری ثبت شد', 409, [
                'scope' => $scope,
            ]);
        }
    }
}
