<?php

namespace App\Services\Suppliers;

use App\Exceptions\DomainException;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Support\Authorization\BranchAccess;

class SupplierSettlementScope
{
    /**
     * Resolve branch-local settlement scope. Never treats a branch account as corporate.
     *
     * @param  array<string, mixed>  $data
     * @return array{supplier_id: int, supplier_account_id: int, branch_id: int}
     */
    public function resolve(User $user, array $data): array
    {
        if (!empty($data['supplier_account_id'])) {
            $account = SupplierAccount::query()->whereKey((int) $data['supplier_account_id'])->first();
            if (!$account) {
                throw new DomainException('حساب تأمین‌کننده یافت نشد', 404, [
                    'error' => 'supplier_account_unresolved',
                ]);
            }
            BranchAccess::assertCanSelectSupplierAccountForSettlement($user, $account);
            BranchAccess::assertCanMutateSettlement($user, (int) $account->branch_id);

            if (!empty($data['branch_id']) && (int) $data['branch_id'] !== (int) $account->branch_id) {
                throw new DomainException('حساب تأمین‌کننده متعلق به این شعبه نیست', 403, [
                    'error' => 'supplier_account_branch_mismatch',
                ]);
            }
            if (!$account->supplier_id) {
                throw new DomainException('حساب تأمین‌کننده بدون هویت متعارف قابل تسویه نیست', 422, [
                    'error' => 'supplier_account_unresolved',
                ]);
            }

            return [
                'supplier_id' => (int) $account->supplier_id,
                'supplier_account_id' => (int) $account->id,
                'branch_id' => (int) $account->branch_id,
            ];
        }

        if (empty($data['supplier_id'])) {
            throw new DomainException('حساب تأمین‌کننده یا شناسه تأمین‌کننده الزامی است', 422, [
                'error' => 'supplier_account_unresolved',
            ]);
        }

        $branchId = array_key_exists('branch_id', $data) && $data['branch_id'] !== null && $data['branch_id'] !== ''
            ? (int) $data['branch_id']
            : null;
        if ($branchId === null && !BranchAccess::canMutateCorporateFinance($user) && $user->branch_id) {
            $branchId = (int) $user->branch_id;
        }
        if ($branchId === null) {
            throw new DomainException('تسویه شعبه‌ای نیاز به branch_id یا supplier_account_id دارد', 422, [
                'error' => 'supplier_account_unresolved',
            ]);
        }

        BranchAccess::assertCanMutateSettlement($user, $branchId);
        $resolved = app(SupplierAccountResolver::class)->resolveForMutation(
            $branchId,
            null,
            (int) $data['supplier_id'],
            true
        );
        if (!$resolved['supplier_id']) {
            throw new DomainException('تسویه به تأمین‌کننده متعارف نیاز دارد', 422, [
                'error' => 'supplier_account_unresolved',
            ]);
        }

        return [
            'supplier_id' => (int) $resolved['supplier_id'],
            'supplier_account_id' => (int) $resolved['account']->id,
            'branch_id' => $branchId,
        ];
    }
}
