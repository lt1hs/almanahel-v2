<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function assertAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $actor = $request->user();
        if (!in_array($actor->role, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'دسترسی غیرمجاز'], 403);
        }

        return null;
    }

    private function allowedRoles(Request $request): array
    {
        if ($request->user()->role === 'super_admin') {
            return ['super_admin', 'admin', 'branch_manager', 'accountant', 'warehouse_staff'];
        }

        return ['admin', 'branch_manager', 'accountant', 'warehouse_staff'];
    }

    public function index(Request $request)
    {
        if ($denied = $this->assertAdmin($request)) {
            return $denied;
        }

        $query = User::with('branch')->orderBy('name');

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }
        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get()->makeHidden(['password', 'remember_token']));
    }

    public function store(Request $request)
    {
        if ($denied = $this->assertAdmin($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:6',
            'role'       => ['required', Rule::in($this->allowedRoles($request))],
            'branch_id'  => 'nullable|exists:branches,id',
            'status'     => 'nullable|in:active,inactive,suspended',
            'iraq_only_visible_branches' => 'nullable|array',
            'iraq_only_visible_branches.*' => 'integer|exists:branches,id',
        ]);

        if (in_array($validated['role'], ['branch_manager', 'warehouse_staff', 'accountant'], true) && empty($validated['branch_id'])) {
            return response()->json(['message' => 'برای این نقش، انتخاب شعبه الزامی است'], 422);
        }

        $user = User::create([
            ...$validated,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json($user->load('branch')->makeHidden(['password', 'remember_token']), 201);
    }

    public function update(Request $request, User $user)
    {
        if ($denied = $this->assertAdmin($request)) {
            return $denied;
        }

        $actor = $request->user();

        if ($user->role === 'super_admin' && $actor->role !== 'super_admin') {
            return response()->json(['message' => 'ویرایش مدیر ارشد فقط توسط مدیر ارشد مجاز است'], 403);
        }

        $validated = $request->validate([
            'name'       => 'sometimes|required|string|max:255',
            'email'      => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password'   => 'nullable|string|min:6',
            'role'       => ['sometimes', 'required', Rule::in($this->allowedRoles($request))],
            'branch_id'  => 'nullable|exists:branches,id',
            'status'     => 'sometimes|in:active,inactive,suspended',
            'iraq_only_visible_branches' => 'nullable|array',
            'iraq_only_visible_branches.*' => 'integer|exists:branches,id',
        ]);

        if (isset($validated['role']) && in_array($validated['role'], ['branch_manager', 'warehouse_staff', 'accountant'], true)) {
            $branchId = $validated['branch_id'] ?? $user->branch_id;
            if (empty($branchId)) {
                return response()->json(['message' => 'برای این نقش، انتخاب شعبه الزامی است'], 422);
            }
        }

        if ($user->id === $actor->id && isset($validated['status']) && $validated['status'] !== 'active') {
            return response()->json(['message' => 'نمی‌توانید حساب خود را غیرفعال کنید'], 422);
        }

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json($user->fresh()->load('branch')->makeHidden(['password', 'remember_token']));
    }

    public function destroy(Request $request, User $user)
    {
        if ($denied = $this->assertAdmin($request)) {
            return $denied;
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'نمی‌توانید حساب خود را حذف کنید'], 422);
        }

        if ($user->role === 'super_admin' && $request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'حذف مدیر ارشد فقط توسط مدیر ارشد مجاز است'], 403);
        }

        $user->update(['status' => 'inactive']);

        return response()->json(['message' => 'کاربر غیرفعال شد']);
    }
}
