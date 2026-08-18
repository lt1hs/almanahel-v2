<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class IntakePolicy
{
    public static function isIntakeHub(Branch $branch): bool
    {
        if ($branch->is_intake_hub || $branch->is_central_warehouse) {
            return true;
        }

        // Legacy fallback until capabilities backfilled
        if ($branch->type === 'warehouse') {
            return true;
        }

        return $branch->type === 'store' && $branch->city === 'قم';
    }

    public static function isIraqStore(Branch $branch): bool
    {
        if ($branch->is_iraq_store) {
            return true;
        }

        return $branch->type === 'store' && $branch->country === 'عراق';
    }

    public static function qomBranch(): ?Branch
    {
        return Branch::where('is_intake_hub', true)->where('type', 'store')->first()
            ?? Branch::where('type', 'store')->where('city', 'قم')->first();
    }

    public static function warehouseBranch(): ?Branch
    {
        return Branch::where('is_central_warehouse', true)->first()
            ?? Branch::where('type', 'warehouse')->first();
    }

    public static function iraqBranch(): ?Branch
    {
        return Branch::where('is_iraq_store', true)->first()
            ?? Branch::where('type', 'store')->where('country', 'عراق')->first();
    }

    /** @return \Illuminate\Support\Collection<int, Branch> */
    public static function iraqBranches()
    {
        $flagged = Branch::where('is_iraq_store', true)->get();
        if ($flagged->isNotEmpty()) {
            return $flagged;
        }

        return Branch::where('type', 'store')->where('country', 'عراق')->get();
    }

    public static function userCanIntake(User $user, bool $iraqOnly = false): bool
    {
        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return true;
        }

        $branch = $user->branch_id ? Branch::find($user->branch_id) : null;

        if ($iraqOnly) {
            return $branch && self::isIraqStore($branch);
        }

        if ($user->role === 'warehouse_staff') {
            $warehouse = self::warehouseBranch();
            return $warehouse && (int) $user->branch_id === (int) $warehouse->id;
        }

        if ($user->role === 'branch_manager' && $branch) {
            return true;
        }

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

        if ($user->branch_id) {
            return (int) $user->branch_id;
        }

        return self::qomBranch()?->id ?? self::warehouseBranch()?->id;
    }

    /** Returns JsonResponse on violation, null when allowed */
    public static function assertIntakeAllowed(User $user, int $branchId, bool $iraqOnly = false): ?JsonResponse
    {
        $branch = Branch::find($branchId);
        if (!$branch) {
            return response()->json(['message' => 'شعبه یافت نشد'], 422);
        }

        if ($iraqOnly && !self::isIraqStore($branch)) {
            return response()->json(['message' => 'کتب عراق فقط در شعب عراق قابل ورود هستند'], 422);
        }

        if (!$iraqOnly && self::isIraqStore($branch) && !in_array($user->role, ['super_admin', 'admin'], true)) {
            // allow admins; others use Iraq flow
        }

        if (!self::userCanIntake($user, $iraqOnly) && !in_array($user->role, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'اجازه ورود کالا به این شعبه را ندارید'], 403);
        }

        if (!in_array($user->role, ['super_admin', 'admin'], true)) {
            if ($user->role === 'warehouse_staff') {
                $wh = self::warehouseBranch();
                $allowed = ($wh && (int) $branchId === (int) $wh->id) || self::isIntakeHub($branch);
                if (!$allowed) {
                    return response()->json(['message' => 'اجازه ورود به این شعبه را ندارید'], 403);
                }
            } elseif ($user->branch_id && (int) $user->branch_id !== (int) $branchId) {
                return response()->json(['message' => 'اجازه ورود به این شعبه را ندارید'], 403);
            }
        }

        return null;
    }
}
