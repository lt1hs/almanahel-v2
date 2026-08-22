<?php

namespace App\Support\Catalog;

use App\Models\User;
use App\Support\Authorization\BranchAccess;
use App\Support\IntakePolicy;

final class CatalogReadScope
{
    private function __construct(
        public readonly bool $aggregate,
        public readonly ?int $branchId,
    ) {}

    public static function resolve(?User $user, ?int $requestedBranchId, bool $aggregate): self
    {
        BranchAccess::assertCanReadCatalog($user);

        if (BranchAccess::isAdmin($user)) {
            if ($aggregate) {
                BranchAccess::assertCanAggregateCatalog($user);

                return new self(true, null);
            }
            if (!$requestedBranchId) {
                BranchAccess::deny('برای مشاهده کاتالوگ، branch_id یا aggregate=1 الزامی است', 422);
            }

            return new self(false, (int) $requestedBranchId);
        }

        if ($aggregate) {
            BranchAccess::deny('گزارش تجمیعی کاتالوگ فقط برای مدیر مجاز است');
        }

        if ($user->role === 'warehouse_staff') {
            $branchId = $requestedBranchId ? (int) $requestedBranchId : ($user->branch_id ? (int) $user->branch_id : null);
            if (!$branchId) {
                BranchAccess::deny('شعبه انبار مشخص نیست', 422);
            }
            if (!in_array($branchId, self::warehouseCatalogBranchIds($user), true)) {
                BranchAccess::deny('اجازه مشاهده کاتالوگ این شعبه را ندارید');
            }

            return new self(false, $branchId);
        }

        $branchId = BranchAccess::resolveCatalogBranchId($user, $requestedBranchId);

        return new self(false, $branchId);
    }

    /** @return int[] */
    private static function warehouseCatalogBranchIds(User $user): array
    {
        $ids = [];
        if ($user->branch_id) {
            $ids[] = (int) $user->branch_id;
        }
        $warehouse = IntakePolicy::warehouseBranch();
        if ($warehouse) {
            $ids[] = (int) $warehouse->id;
        }

        return array_values(array_unique($ids));
    }
}
