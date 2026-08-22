<?php

namespace App\Services\Suppliers;

use App\Exceptions\DomainException;
use App\Models\Supplier;
use App\Models\SupplierAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierAccountResolver
{
    /**
     * @return array{account: SupplierAccount, supplier_id: ?int, legacy_used: bool}
     */
    public function resolveForMutation(
        int $branchId,
        mixed $supplierAccountId,
        mixed $supplierId,
        bool $createIfMissing = false
    ): array {
        if ($supplierAccountId !== null && $supplierAccountId !== '') {
            $account = SupplierAccount::query()->whereKey((int) $supplierAccountId)->first();
            if (!$account) {
                throw new DomainException('حساب تأمین‌کننده یافت نشد', 404, [
                    'error' => 'supplier_account_unresolved',
                ]);
            }
            if ((int) $account->branch_id !== $branchId) {
                throw new DomainException('حساب تأمین‌کننده متعلق به این شعبه نیست', 403, [
                    'error' => 'supplier_account_branch_mismatch',
                ]);
            }

            return [
                'account' => $account,
                'supplier_id' => $account->supplier_id ? (int) $account->supplier_id : null,
                'legacy_used' => false,
            ];
        }

        if ($supplierId === null || $supplierId === '') {
            throw new DomainException('حساب تأمین‌کننده یا شناسه تأمین‌کننده الزامی است', 422, [
                'error' => 'supplier_account_unresolved',
            ]);
        }

        $account = $this->findByPair($branchId, (int) $supplierId);
        if (!$account && $createIfMissing) {
            $account = $this->ensureForPair($branchId, (int) $supplierId);
        }
        if (!$account) {
            throw new DomainException('حساب تأمین‌کننده برای این شعبه یافت نشد', 422, [
                'error' => 'supplier_account_unresolved',
            ]);
        }

        Log::notice('supplier_id_legacy_used', [
            'branch_id' => $branchId,
            'supplier_id' => (int) $supplierId,
            'supplier_account_id' => $account->id,
        ]);

        return [
            'account' => $account,
            'supplier_id' => (int) $supplierId,
            'legacy_used' => true,
        ];
    }

    public function findByPair(int $branchId, int $supplierId): ?SupplierAccount
    {
        $matches = SupplierAccount::query()
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplierId)
            ->get();
        if ($matches->count() > 1) {
            throw new DomainException('بیش از یک حساب تأمین‌کننده برای این شعبه وجود دارد', 422, [
                'error' => 'supplier_account_ambiguous',
                'branch_id' => $branchId,
                'supplier_id' => $supplierId,
            ]);
        }

        return $matches->first();
    }

    public function findIdByPair(int $branchId, ?int $supplierId): ?int
    {
        if (!$supplierId) {
            return null;
        }
        try {
            return $this->findByPair($branchId, $supplierId)?->id;
        } catch (DomainException) {
            return null;
        }
    }

    public function ensureForPair(int $branchId, int $supplierId): SupplierAccount
    {
        $existing = $this->findByPair($branchId, $supplierId);
        if ($existing) {
            return $existing;
        }

        $supplier = Supplier::query()->whereKey($supplierId)->first();
        if (!$supplier) {
            throw new DomainException('تأمین‌کننده یافت نشد', 422, [
                'error' => 'supplier_account_unresolved',
            ]);
        }

        return DB::transaction(function () use ($branchId, $supplier) {
            $again = SupplierAccount::query()
                ->where('branch_id', $branchId)
                ->where('supplier_id', $supplier->id)
                ->lockForUpdate()
                ->first();
            if ($again) {
                return $again;
            }

            $this->assertUniquePair($branchId, (int) $supplier->id, null);

            return SupplierAccount::create([
                'supplier_id' => $supplier->id,
                'branch_id' => $branchId,
                'display_name' => $supplier->name,
                'type' => $supplier->type ?: 'publisher',
                'status' => 'active',
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'city' => $supplier->city,
            ]);
        });
    }

    public function assertUniquePair(int $branchId, ?int $supplierId, ?int $ignoreId, ?string $localCode = null): void
    {
        if ($supplierId) {
            $q = SupplierAccount::query()
                ->where('branch_id', $branchId)
                ->where('supplier_id', $supplierId);
            if ($ignoreId) {
                $q->where('id', '!=', $ignoreId);
            }
            if ($q->exists()) {
                throw new DomainException('این تأمین‌کننده در این شعبه حساب دارد', 422, [
                    'error' => 'supplier_account_duplicate',
                ]);
            }
        }

        $code = $localCode !== null && $localCode !== '' ? $localCode : null;
        if ($code !== null) {
            $q = SupplierAccount::query()
                ->where('branch_id', $branchId)
                ->where('local_code', $code);
            if ($ignoreId) {
                $q->where('id', '!=', $ignoreId);
            }
            if ($q->exists()) {
                throw new DomainException('کد محلی تأمین‌کننده در این شعبه تکراری است', 422, [
                    'error' => 'supplier_account_local_code_duplicate',
                ]);
            }
        }
    }

    /**
     * Create a branch-local account, atomically provisioning a hidden canonical supplier when needed.
     *
     * @param  array<string, mixed>  $validated
     */
    public function createAccount(array $validated): SupplierAccount
    {
        $branchId = (int) $validated['branch_id'];
        $localCode = !empty($validated['local_code']) ? $validated['local_code'] : null;
        $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null;
        $createdCanonical = false;

        return DB::transaction(function () use ($validated, $branchId, $localCode, $supplierId, &$createdCanonical) {
            if (!$supplierId) {
                $supplier = Supplier::create([
                    'name' => $validated['display_name'],
                    'phone' => $validated['phone'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'type' => $validated['type'] ?? 'publisher',
                    'status' => 'active',
                    'identity_origin' => 'branch_local',
                    'origin_branch_id' => $branchId,
                ]);
                $supplierId = (int) $supplier->id;
                $createdCanonical = true;
            }

            $this->assertUniquePair($branchId, $supplierId, null, $localCode);

            return SupplierAccount::create([
                'branch_id' => $branchId,
                'supplier_id' => $supplierId,
                'display_name' => $validated['display_name'],
                'local_code' => $localCode,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'type' => $validated['type'] ?? 'publisher',
                'payment_terms' => $validated['payment_terms'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'created_canonical_supplier' => $createdCanonical,
            ]);
        });
    }
}
