<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierAccount;
use App\Services\Suppliers\SupplierAccountBalance;
use App\Services\Suppliers\SupplierAccountResolver;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierAccountController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $aggregate = $request->boolean('aggregate');
        $financial = $request->boolean('financial');

        if ($aggregate) {
            if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch') || $request->isMethod('delete')) {
                BranchAccess::deny('حالت تجمیعی فقط برای گزارش خواندنی مجاز است', 422);
            }
            BranchAccess::assertCanAggregateSupplierAccounts($user);
        } elseif ($financial) {
            $branchId = BranchAccess::resolveSupplierAccountBranchId(
                $user,
                $request->filled('branch_id') ? (int) $request->branch_id : null
            );
            BranchAccess::assertCanListSupplierAccountsForSettlement($user, $branchId);
        } else {
            $branchId = BranchAccess::resolveSupplierAccountBranchId(
                $user,
                $request->filled('branch_id') ? (int) $request->branch_id : null
            );
            BranchAccess::assertCanListSupplierAccountsForOperations($user, $branchId);
        }

        $query = SupplierAccount::query()->with(['supplier:id,name,type,status,identity_origin', 'branch:id,name']);

        if (!$aggregate) {
            $branchId = BranchAccess::resolveSupplierAccountBranchId(
                $user,
                $request->filled('branch_id') ? (int) $request->branch_id : null
            );
            $query->where('branch_id', $branchId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('display_name', 'like', $q)
                    ->orWhere('local_code', 'like', $q)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $q));
            });
        }

        $mapper = $financial
            ? fn (SupplierAccount $row) => $this->presentFinancialAccount($row)
            : fn (SupplierAccount $row) => $this->presentAccount($row);

        return response()->json($query->orderBy('display_name')->get()->map($mapper));
    }

    public function store(Request $request)
    {
        if ($request->boolean('aggregate')) {
            return response()->json([
                'message' => 'حالت تجمیعی برای ثبت مجاز نیست',
                'error' => 'aggregate_not_allowed',
            ], 422);
        }

        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'display_name' => 'required|string|max:255',
            'local_code' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:32',
            'payment_terms' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',
        ]);

        BranchAccess::assertCanMutateInBranch($request->user(), (int) $validated['branch_id']);
        if (!BranchAccess::isAdmin($request->user()) && $request->user()->role !== 'branch_manager') {
            BranchAccess::deny('اجازه ایجاد حساب تأمین‌کننده را ندارید');
        }

        $account = app(SupplierAccountResolver::class)->createAccount($validated);
        $account->load(['supplier', 'branch']);

        ActivityLogger::record(
            'supplier_accounts',
            'created',
            "ایجاد حساب تأمین‌کننده «{$account->display_name}»",
            $account,
            [
                'supplier_account_id' => $account->id,
                'supplier_id' => $account->supplier_id,
                'branch_id' => $account->branch_id,
                'created_canonical_supplier' => $account->created_canonical_supplier,
            ],
            (int) $account->branch_id,
        );

        return response()->json($this->presentAccount($account), 201);
    }

    public function show(Request $request, SupplierAccount $supplierAccount)
    {
        BranchAccess::assertCanViewSupplierAccountDetails($request->user(), $supplierAccount);

        return response()->json($this->presentAccount($supplierAccount->load(['supplier', 'branch'])));
    }

    public function update(Request $request, SupplierAccount $supplierAccount)
    {
        if ($request->boolean('aggregate')) {
            return response()->json([
                'message' => 'حالت تجمیعی برای ثبت مجاز نیست',
                'error' => 'aggregate_not_allowed',
            ], 422);
        }

        BranchAccess::assertCanMutateSupplierAccount($request->user(), $supplierAccount);

        $validated = $request->validate([
            'display_name' => 'sometimes|required|string|max:255',
            'local_code' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:32',
            'payment_terms' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',
        ]);

        $localCode = array_key_exists('local_code', $validated)
            ? (!empty($validated['local_code']) ? $validated['local_code'] : null)
            : $supplierAccount->local_code;

        DB::transaction(function () use ($validated, $localCode, $supplierAccount) {
            app(SupplierAccountResolver::class)->assertUniquePair(
                (int) $supplierAccount->branch_id,
                $supplierAccount->supplier_id ? (int) $supplierAccount->supplier_id : null,
                (int) $supplierAccount->id,
                $localCode
            );
            if (array_key_exists('local_code', $validated)) {
                $validated['local_code'] = $localCode;
            }
            $supplierAccount->update($validated);
        });

        ActivityLogger::record(
            'supplier_accounts',
            'updated',
            "ویرایش حساب تأمین‌کننده «{$supplierAccount->display_name}»",
            $supplierAccount,
            [
                'supplier_account_id' => $supplierAccount->id,
                'supplier_id' => $supplierAccount->supplier_id,
            ],
            (int) $supplierAccount->branch_id,
        );

        return response()->json($this->presentAccount($supplierAccount->fresh()->load(['supplier', 'branch'])));
    }

    public function balance(Request $request, SupplierAccount $supplierAccount)
    {
        if ($request->boolean('aggregate')) {
            return response()->json([
                'message' => 'تراز حساب شعبه تجمیع نمی‌شود',
                'error' => 'aggregate_not_allowed',
            ], 422);
        }
        BranchAccess::assertCanViewSupplierAccountDetails($request->user(), $supplierAccount);

        return response()->json(app(SupplierAccountBalance::class)->forAccount(
            $supplierAccount,
            $request->input('from'),
            $request->input('to'),
        ));
    }

    /** @return array<string, mixed> */
    private function presentAccount(SupplierAccount $account): array
    {
        $data = $account->toArray();
        $data['name'] = $account->display_name;
        if ($account->relationLoaded('supplier') && $account->supplier) {
            $data['supplier_name'] = $account->supplier->name;
        }

        return $data;
    }

    /** Minimal settlement selector — no operational contact fields. */
    private function presentFinancialAccount(SupplierAccount $account): array
    {
        return [
            'id' => $account->id,
            'supplier_account_id' => $account->id,
            'supplier_id' => $account->supplier_id,
            'branch_id' => $account->branch_id,
            'display_name' => $account->display_name,
            'name' => $account->display_name,
            'status' => $account->status,
        ];
    }
}
