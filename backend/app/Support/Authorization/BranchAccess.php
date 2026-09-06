<?php

namespace App\Support\Authorization;

use App\Models\Book;
use App\Models\Branch;
use App\Models\BranchCatalogItem;
use App\Models\SupplierAccount;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

class BranchAccess
{
    public const ADMIN_ROLES = ['super_admin', 'admin'];

    public static function isAdmin(?User $user): bool
    {
        return $user && in_array($user->role, self::ADMIN_ROLES, true);
    }

    public static function isSuperAdmin(?User $user): bool
    {
        return $user && $user->role === 'super_admin';
    }

    public static function canManageUsers(?User $user): bool
    {
        return self::isAdmin($user);
    }

    public static function canViewAllReports(?User $user): bool
    {
        return self::isAdmin($user) || ($user && $user->role === 'accountant');
    }

    public static function canViewFinancialReports(?User $user): bool
    {
        if (!$user || $user->role === 'warehouse_staff') {
            return false;
        }

        return in_array($user->role, ['super_admin', 'admin', 'accountant', 'branch_manager'], true);
    }

    public static function assertCanViewFinancialReports(?User $user): void
    {
        if (!self::canViewFinancialReports($user)) {
            self::deny('دسترسی به گزارش‌های مالی مجاز نیست');
        }
    }

    public static function resolveReportBranchId(?User $user, mixed $requested): ?int
    {
        self::assertCanViewFinancialReports($user);
        if ($user->role === 'branch_manager') {
            if ($requested !== null && $requested !== '' && (int) $requested !== (int) $user->branch_id) {
                self::deny('اجازه مشاهده گزارش شعبه دیگر را ندارید');
            }

            return $user->branch_id ? (int) $user->branch_id : null;
        }
        if ($requested === null || $requested === '') {
            return null;
        }

        return (int) $requested;
    }

    public static function canManageSettings(?User $user): bool
    {
        return self::isAdmin($user);
    }

    public static function canManageBranches(?User $user): bool
    {
        return self::isAdmin($user);
    }

    public static function canViewActivityLogs(?User $user): bool
    {
        return self::isAdmin($user);
    }

    /** Branches this user may see in operational alerts (bell / dashboard). null = all. */
    public static function alertBranchIds(?User $user): ?array
    {
        if (!$user) {
            return [];
        }
        if (self::isAdmin($user) || $user->role === 'accountant') {
            return null;
        }
        if (!$user->branch_id) {
            return [];
        }

        return [(int) $user->branch_id];
    }

    /** @return int[]|null null = all branches */
    public static function visibleBranchIds(?User $user): ?array
    {
        if (!$user) {
            return [];
        }
        if (self::isAdmin($user) || $user->role === 'accountant') {
            return null;
        }

        $ids = [];
        if ($user->branch_id) {
            $ids[] = (int) $user->branch_id;
        }
        foreach ($user->iraq_only_visible_branches ?? [] as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public static function assertBranchAllowed(?User $user, int $branchId): void
    {
        $ids = self::visibleBranchIds($user);
        if ($ids === null) {
            return;
        }
        if (!in_array($branchId, $ids, true)) {
            self::deny('اجازه دسترسی به این شعبه را ندارید');
        }
    }

    /**
     * Branches this user may mutate financially.
     * iraq_only_visible_branches is visibility-only and is never included.
     *
     * @return int[]|null null = all branches
     */
    public static function mutableBranchIds(?User $user): ?array
    {
        if (!self::canMutateFinance($user)) {
            return [];
        }
        if (self::isAdmin($user)) {
            return null;
        }
        if ($user->role === 'accountant' || $user->role === 'branch_manager') {
            if (!$user->branch_id) {
                return [];
            }

            return [(int) $user->branch_id];
        }
        if (!$user->branch_id) {
            return [];
        }

        return [(int) $user->branch_id];
    }

    public static function assertCanMutateInBranch(?User $user, int $branchId): void
    {
        self::assertCanMutateFinance($user);
        $ids = self::mutableBranchIds($user);
        if ($ids === null) {
            return;
        }
        if (!in_array($branchId, $ids, true)) {
            self::deny('اجازه ثبت رویداد مالی در این شعبه را ندارید');
        }
    }

    public static function assertCanViewAllReports(?User $user): void
    {
        if (!self::canViewAllReports($user)) {
            self::deny('دسترسی به گزارش‌های سراسری مجاز نیست');
        }
    }

    public static function assertCanManageSettings(?User $user): void
    {
        if (!self::canManageSettings($user)) {
            self::deny('تغییر تنظیمات فقط برای مدیران مجاز است');
        }
    }

    public static function assertCanManageUsers(?User $user): void
    {
        if (!self::canManageUsers($user)) {
            self::deny('مدیریت کاربران فقط برای مدیران مجاز است');
        }
    }

    public static function assertCanViewActivityLogs(?User $user): void
    {
        if (!self::canViewActivityLogs($user)) {
            self::deny('مشاهده لاگ فعالیت فقط برای مدیران مجاز است');
        }
    }

    public static function canSeeIraqOnlyBooks(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        if (self::isAdmin($user)) {
            return true;
        }
        if (!empty($user->iraq_only_visible_branches)) {
            return true;
        }
        if (!$user->branch_id) {
            return false;
        }
        $branch = Branch::find($user->branch_id);

        return $branch && (
            ($branch->country ?? '') === 'عراق'
            || (bool) ($branch->is_iraq_store ?? false)
        );
    }

    public static function assertIraqBookVisible(?User $user, Book $book): void
    {
        if (!$book->iraq_only) {
            return;
        }
        if (!self::canSeeIraqOnlyBooks($user)) {
            self::deny('دسترسی به کتب عراق مجاز نیست');
        }
    }

    public static function resolveActorBranchId(User $user, ?int $requestedBranchId): int
    {
        if (self::isAdmin($user) || $user->role === 'accountant') {
            if (!$requestedBranchId) {
                self::deny('انتخاب شعبه الزامی است', 422);
            }
            return (int) $requestedBranchId;
        }

        if (!$user->branch_id) {
            self::deny('شعبه کاربر مشخص نیست', 422);
        }

        if ($requestedBranchId && (int) $requestedBranchId !== (int) $user->branch_id) {
            self::deny('اجازه ثبت برای شعبه دیگر را ندارید');
        }

        return (int) $user->branch_id;
    }

    public static function canOverridePrice(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        if (self::isAdmin($user)) {
            return true;
        }

        return (bool) config('almanahel.allow_branch_price_override', true)
            && in_array($user->role, ['branch_manager', 'accountant'], true);
    }

    public static function canChangeSellingPrice(?User $user, int $branchId, bool $intake = false): bool
    {
        if (!$user) {
            return false;
        }
        if (self::isAdmin($user)) {
            return true;
        }
        if ($user->role === 'warehouse_staff') {
            return $intake && $user->branch_id && (int) $user->branch_id === $branchId;
        }
        if ($user->role === 'branch_manager') {
            return (bool) $user->branch_id && (int) $user->branch_id === $branchId;
        }

        return false;
    }

    public static function assertCanChangeSellingPrice(?User $user, int $branchId, bool $intake = false): void
    {
        if (!self::canChangeSellingPrice($user, $branchId, $intake)) {
            self::deny('اجازه تغییر قیمت فروش این شعبه را ندارید');
        }
    }

    public static function canChangeConsignmentCost(?User $user): bool
    {
        return self::isAdmin($user);
    }

    public static function assertCanChangeConsignmentCost(?User $user): void
    {
        if (!self::canChangeConsignmentCost($user)) {
            self::deny('تغییر بهای امانی فقط برای مدیر مجاز است');
        }
    }

    public static function canViewPriceHistory(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return in_array($user->role, ['super_admin', 'admin', 'accountant', 'branch_manager'], true);
    }

    public static function assertCanViewPriceHistory(?User $user): void
    {
        if (!self::canViewPriceHistory($user)) {
            self::deny('اجازه مشاهده تاریخچه قیمت را ندارید');
        }
    }

    public static function canMutateFinance(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        if ($user->role === 'warehouse_staff') {
            return false;
        }

        return in_array($user->role, ['super_admin', 'admin', 'accountant', 'branch_manager'], true);
    }

    public static function canMutateCorporateFinance(?User $user): bool
    {
        return self::isAdmin($user);
    }

    public static function assertCanMutateFinance(?User $user): void
    {
        if (!self::canMutateFinance($user)) {
            self::deny('اجازه تغییر رویدادهای مالی را ندارید');
        }
    }

    public static function canMutateStock(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return in_array($user->role, ['super_admin', 'admin', 'warehouse_staff', 'branch_manager'], true);
    }

    /**
     * Branches this user may mutate stock on.
     * iraq_only_visible_branches is visibility-only and is never included.
     *
     * @return int[]|null null = all branches
     */
    public static function stockMutableBranchIds(?User $user): ?array
    {
        if (!self::canMutateStock($user)) {
            return [];
        }
        if (self::isAdmin($user)) {
            return null;
        }
        if (!$user->branch_id) {
            return [];
        }

        return [(int) $user->branch_id];
    }

    public static function assertCanMutateStockInBranch(?User $user, int $branchId): void
    {
        if (!self::canMutateStock($user)) {
            self::deny('اجازه تغییر موجودی انبار را ندارید');
        }
        $ids = self::stockMutableBranchIds($user);
        if ($ids === null) {
            return;
        }
        if (!in_array($branchId, $ids, true)) {
            self::deny('اجازه تغییر موجودی این شعبه را ندارید');
        }
    }

    public static function assertCanMutateSettlement(?User $user, ?int $branchId): void
    {
        self::assertCanMutateFinance($user);
        if ($branchId === null) {
            if (!self::canMutateCorporateFinance($user)) {
                self::deny('فقط مدیر یا حسابدار می‌تواند تسویه شرکتی ثبت کند');
            }

            return;
        }
        self::assertCanMutateInBranch($user, $branchId);
    }

    /** Canonical supplier directory — admin/super_admin only. */
    public static function assertCanViewCanonicalSuppliers(?User $user): void
    {
        if (!self::isAdmin($user)) {
            self::deny('دسترسی به فهرست تأمین‌کنندگان متعارف فقط برای مدیران مجاز است');
        }
    }

    /** Admin-only cross-branch supplier account reporting. */
    public static function assertCanAggregateSupplierAccounts(?User $user): void
    {
        if (!self::isAdmin($user)) {
            self::deny('حالت تجمیعی حساب تأمین‌کننده فقط برای مدیران مجاز است');
        }
    }

    /**
     * Operational supplier-account details (contacts, full profile) — admin or own branch manager.
     */
    public static function assertCanViewSupplierAccountDetails(?User $user, SupplierAccount $account): void
    {
        if (!$user) {
            self::deny('احراز هویت نشده', 401);
        }
        if (self::isAdmin($user)) {
            return;
        }
        if ($user->role !== 'branch_manager' || !$user->branch_id || (int) $user->branch_id !== (int) $account->branch_id) {
            self::deny('اجازه مشاهده جزئیات حساب تأمین‌کننده این شعبه را ندارید');
        }
    }

    /**
     * Create/update branch-local supplier accounts.
     */
    public static function assertCanMutateSupplierAccount(?User $user, SupplierAccount $account): void
    {
        self::assertCanMutateInBranch($user, (int) $account->branch_id);
        if (!self::isAdmin($user) && $user->role !== 'branch_manager') {
            self::deny('اجازه ویرایش حساب تأمین‌کننده را ندارید');
        }
    }

    /**
     * Settlement may reference an account without exposing operational contact details.
     */
    public static function assertCanSelectSupplierAccountForSettlement(?User $user, SupplierAccount $account): void
    {
        if (!$user) {
            self::deny('احراز هویت نشده', 401);
        }
        if (self::isAdmin($user)) {
            return;
        }
        if ($user->role === 'accountant') {
            self::assertCanMutateFinance($user);
            self::assertCanMutateInBranch($user, (int) $account->branch_id);

            return;
        }
        if ($user->role === 'branch_manager' && $user->branch_id && (int) $user->branch_id === (int) $account->branch_id) {
            return;
        }
        self::deny('اجازه استفاده از حساب تأمین‌کننده این شعبه را ندارید');
    }

    /** Full operational list for branch POS/intake — not for accountants. */
    public static function assertCanListSupplierAccountsForOperations(?User $user, int $branchId): void
    {
        if (self::isAdmin($user)) {
            self::assertCanMutateInBranch($user, $branchId);

            return;
        }
        if ($user->role === 'branch_manager' && $user->branch_id && (int) $user->branch_id === $branchId) {
            return;
        }
        self::deny('اجازه فهرست حساب‌های تأمین‌کننده این شعبه را ندارید');
    }

    /** Minimal financial selector list — accountants with explicit branch only. */
    public static function assertCanListSupplierAccountsForSettlement(?User $user, int $branchId): void
    {
        self::assertCanMutateFinance($user);
        if (self::isAdmin($user)) {
            return;
        }
        if ($user->role === 'accountant') {
            if (!$user->branch_id) {
                self::deny('حسابدار بدون شعبه اجازه فهرست حساب تسویه را ندارد', 422);
            }
            if ((int) $user->branch_id !== $branchId) {
                self::deny('اجازه فهرست حساب‌های تسویه این شعبه را ندارید');
            }

            return;
        }
        if ($user->role === 'branch_manager' && $user->branch_id && (int) $user->branch_id === $branchId) {
            return;
        }
        self::deny('اجازه فهرست حساب‌های تسویه این شعبه را ندارید');
    }

    /** @deprecated use assertCanViewSupplierAccountDetails or assertCanSelectSupplierAccountForSettlement */
    public static function assertCanViewSupplierAccount(?User $user, SupplierAccount $account): void
    {
        self::assertCanViewSupplierAccountDetails($user, $account);
    }

    /** Branch for supplier-account list/selectors — excludes iraq visibility-only branches. */
    public static function resolveSupplierAccountBranchId(User $user, ?int $requestedBranchId): int
    {
        if (self::isAdmin($user)) {
            if (!$requestedBranchId) {
                self::deny('انتخاب شعبه الزامی است', 422);
            }
            self::assertCanMutateInBranch($user, (int) $requestedBranchId);

            return (int) $requestedBranchId;
        }
        if ($user->role === 'accountant') {
            if (!$user->branch_id) {
                self::deny('حسابدار بدون شعبه اجازه فهرست حساب تسویه را ندارد', 422);
            }
            if ($requestedBranchId && (int) $requestedBranchId !== (int) $user->branch_id) {
                self::deny('اجازه دسترسی به شعبه دیگر را ندارید');
            }

            return (int) $user->branch_id;
        }

        return self::resolveActorBranchId($user, $requestedBranchId);
    }

    /**
     * Operational consignment scope (list/show/close/settlement history).
     * Returns null for admin aggregate read-only reporting; otherwise a single branch id.
     */
    public static function resolveOperationalConsignmentScope(User $user, ?int $requestedBranchId, bool $aggregate): ?int
    {
        if ($aggregate) {
            self::assertCanAggregateSupplierAccounts($user);

            return null;
        }

        return self::resolveOperationalBranchId($user, $requestedBranchId);
    }

    public static function assertCanAccessOperationalConsignmentReceipt(User $user, int $receiptBranchId): void
    {
        if (self::isAdmin($user)) {
            return;
        }

        self::resolveOperationalBranchId($user, $receiptBranchId);
    }

    /**
     * Branch scope for operational supplier settlement/debt endpoints.
     * Admins must pass an explicit branch; non-admins are locked to their assigned branch.
     */
    public static function resolveOperationalBranchId(User $user, ?int $requestedBranchId): int
    {
        if (self::isAdmin($user)) {
            if (!$requestedBranchId) {
                self::deny('انتخاب شعبه الزامی است', 422);
            }

            return (int) $requestedBranchId;
        }

        if ($user->role === 'accountant') {
            if (!$user->branch_id) {
                self::deny('حسابدار بدون شعبه اجازه عملیات تأمین‌کننده را ندارد', 422);
            }
            if ($requestedBranchId && (int) $requestedBranchId !== (int) $user->branch_id) {
                self::deny('اجازه عملیات در شعبه دیگر را ندارید');
            }

            return (int) $user->branch_id;
        }

        if ($user->role === 'branch_manager') {
            if (!$user->branch_id) {
                self::deny('شعبه کاربر مشخص نیست', 422);
            }
            if ($requestedBranchId && (int) $requestedBranchId !== (int) $user->branch_id) {
                self::deny('اجازه عملیات در شعبه دیگر را ندارید');
            }

            return (int) $user->branch_id;
        }

        if ($user->role === 'warehouse_staff') {
            if (!$user->branch_id) {
                self::deny('شعبه انبار مشخص نیست', 422);
            }
            if ($requestedBranchId && (int) $requestedBranchId !== (int) $user->branch_id) {
                self::deny('اجازه عملیات در شعبه دیگر را ندارید');
            }

            return (int) $user->branch_id;
        }

        self::deny('اجازه عملیات تأمین‌کننده را ندارید');
    }

    public static function assertCanMutateCheck(?User $user, int $branchId): void
    {
        self::assertCanMutateInBranch($user, $branchId);
    }

    public static function resolveCatalogBranchId(?User $user, ?int $requestedBranchId): ?int
    {
        if (self::isAdmin($user)) {
            return $requestedBranchId ? (int) $requestedBranchId : null;
        }

        if (!$user?->branch_id) {
            self::deny('شعبه کاربر مشخص نیست', 422);
        }

        if ($requestedBranchId && (int) $requestedBranchId !== (int) $user->branch_id) {
            self::deny('اجازه مشاهده کاتالوگ شعبه دیگر را ندارید');
        }

        return (int) $user->branch_id;
    }

    public static function assertCatalogBookVisible(?User $user, int $bookId, ?int $branchId): void
    {
        if ($branchId === null) {
            self::deny('انتخاب شعبه الزامی است', 422, 'branch_required');
        }

        $visible = BranchCatalogItem::query()
            ->where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->where('active', true)
            ->exists();

        if (!$visible) {
            self::deny('این عنوان در کاتالوگ شعبه شما نیست', 404);
        }
    }

    /**
     * Resolve branch for operational catalog mutation (POST /books, pricing link, etc.).
     */
    public static function resolveCatalogMutationBranchId(User $user, ?int $requestedBranchId): int
    {
        if (self::isAdmin($user)) {
            if (!$requestedBranchId) {
                self::deny('انتخاب شعبه الزامی است', 422, 'branch_required');
            }

            return (int) $requestedBranchId;
        }

        if (in_array($user->role, ['branch_manager', 'warehouse_staff'], true)) {
            return self::resolveOperationalBranchId($user, $requestedBranchId);
        }

        self::deny('اجازه تغییر کاتالوگ را ندارید');
    }

    public static function assertCanAggregateCatalog(?User $user): void
    {
        if (!self::isAdmin($user)) {
            self::deny('گزارش تجمیعی کاتالوگ فقط برای مدیر مجاز است');
        }
    }

    public static function assertCanReadCatalog(?User $user): void
    {
        if (!$user) {
            self::deny('احراز هویت نشده', 401);
        }
        if (self::isAdmin($user)) {
            return;
        }
        if (in_array($user->role, ['branch_manager', 'accountant', 'warehouse_staff'], true)) {
            if (!$user->branch_id && $user->role !== 'warehouse_staff') {
                self::deny('شعبه کاربر مشخص نیست', 422);
            }

            return;
        }

        self::deny('دسترسی به کاتالوگ مجاز نیست');
    }

    public static function assertCanMutateCanonicalBook(?User $user): void
    {
        if (!self::isAdmin($user)) {
            self::deny('ویرایش اطلاعات متعارف کتاب فقط برای مدیر مجاز است');
        }
    }

    public static function assertCanMutateBranchCatalog(?User $user): void
    {
        if (!$user) {
            self::deny('احراز هویت نشده', 401);
        }
        if ($user->role === 'accountant') {
            self::deny('حسابدار اجازه تغییر کاتالوگ عملیاتی را ندارد');
        }
        if (!in_array($user->role, ['super_admin', 'admin', 'branch_manager', 'warehouse_staff'], true)) {
            self::deny('اجازه تغییر کاتالوگ را ندارید');
        }
    }

    public static function deny(string $message, int $status = 403, ?string $error = null): never
    {
        $payload = ['message' => $message];
        if ($error !== null) {
            $payload['error'] = $error;
        }

        throw new HttpResponseException(response()->json($payload, $status));
    }
}
