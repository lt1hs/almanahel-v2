<?php

namespace App\Http\Middleware;

use App\Support\Authorization\BranchAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    /** @param  string  ...$roles */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'احراز هویت نشده'], 401);
        }

        if ($roles === [] || $roles === ['admin']) {
            if (!BranchAccess::isAdmin($user)) {
                return response()->json(['message' => 'دسترسی غیرمجاز'], 403);
            }
            return $next($request);
        }

        if ($roles === ['reports']) {
            if (!BranchAccess::canViewAllReports($user)) {
                return response()->json(['message' => 'دسترسی غیرمجاز'], 403);
            }
            return $next($request);
        }

        if (!in_array($user->role, $roles, true) && !BranchAccess::isAdmin($user)) {
            return response()->json(['message' => 'دسترسی غیرمجاز'], 403);
        }

        return $next($request);
    }
}
