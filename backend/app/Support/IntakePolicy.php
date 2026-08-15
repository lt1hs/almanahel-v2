<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class IntakePolicy
{
    /** Qom store or central warehouse — default intake hubs per business rules */
    public static function isIntakeHub(Branch $branch): bool
    {
        if ($branch->type === 'warehouse') {
            return true;
        }

        return $branch->type === 'store' && $branch->city === 'قم';
    }

    public static function isIraqStore(Branch $branch): bool
    {
        return $branch->type === 'store' && $branch->country === 'عراق';
    }

    public static function qomBranch(): ?Branch
    {
        return Branch::where('type', 'store')->where('city', 'قم')->first();
    }

    public static function warehouseBranch(): ?Branch
    {
        return Branch::where('type', 'warehouse')->first();
    }

    public static function iraqBranch(): ?Branch
    {
        return Branch::where('type', 'store')->where('country', 'عراق')->first();
    }

    public static function userCanIntake(User $user, bool $iraqOnly = false): bool
    {
        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return true;
        }

        if ($iraqOnly) {
            $iraq = self::iraqBranch();
            return $iraq && (int) $user->branch_id === (int) $iraq->id;
        }

        if ($user->role === 'warehouse_staff') {
            $warehouse = self::warehouseBranch();
            return $warehouse && (int) $user->branch_id === (int) $warehouse->id;
        }

        $branch = $user->branch_id ? Branch::find($user->branch_id) : null;

        return $branch && self::isIntakeHub($branch);
    }

    public static function defaultIntakeBranchId(User $user, bool $iraqOnly = false): ?int
    {
        if ($iraqOnly) {
            return self::iraqBranch()?->id;
        }

        if ($user->role === 'warehouse_staff') {
            return self::warehouseBranch()?->id ?? self::qomBranch()?->id;
        }

        $branch = $user->branch_id ? Branch::find($user->branch_id) : null;
        if ($branch && self::isIntakeHub($branch)) {
            return $branch->id;
        }

        return self::qomBranch()?->id ?? self::warehouseBranch()?->id;
    }

    /** Returns JsonResponse on violation, null when allowed */
    public static function assertIntakeAllowed(User $user, int $branchId, bool $iraqOnly = false): ?JsonResponse
    {
        $branch = Branch::find($branchId);
        if (!$branch) {
            return response()->json(['message' => 'شعبه یافت نشد'], 404);
        }

        if ($iraqOnly) {
            if (!self::isIraqStore($branch)) {
                return response()->json([
                    'message' => 'کتب مخصوص عراق فقط در شعبه عراق قابل ثبت ورود هستند',
                ], 422);
            }
            if (!self::userCanIntake($user, true)) {
                return response()->json([
                    'message' => 'شما مجوز ثبت ورود کتاب مخصوص عراق را ندارید',
                ], 403);
            }

            return null;
        }

        if (!self::isIntakeHub($branch)) {
            return response()->json([
                'message' => 'ورود کتاب فقط در شعبه قم یا انبار مرکزی مجاز است',
            ], 422);
        }

        if (!self::userCanIntake($user, false)) {
            return response()->json([
                'message' => 'شما مجوز ثبت ورود کتاب در این مرکز را ندارید',
            ], 403);
        }

        return null;
    }
}
