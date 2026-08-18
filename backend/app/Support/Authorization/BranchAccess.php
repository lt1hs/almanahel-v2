<?php

namespace App\Support\Authorization;

use App\Models\Book;
use App\Models\Branch;
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

    public static function deny(string $message, int $status = 403): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
