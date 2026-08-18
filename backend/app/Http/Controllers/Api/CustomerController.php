<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Ledger\LedgerPoster;
use App\Support\Authorization\BranchAccess;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query()->whereNull('archived_at');
        $ids = BranchAccess::visibleBranchIds($request->user());
        if ($ids !== null) {
            $query->where(function ($q) use ($ids) {
                $q->whereIn('branch_id', $ids)->orWhereNull('branch_id');
            });
        }
        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%");
            });
        }

        return response()->json($query->latest()->paginate(30));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'branch_id' => 'nullable|exists:branches,id',
            'notes' => 'nullable|string',
        ]);
        if (!empty($validated['branch_id'])) {
            BranchAccess::assertBranchAllowed($request->user(), (int) $validated['branch_id']);
        }
        $customer = Customer::create($validated);

        return response()->json($customer, 201);
    }

    public function show(Request $request, Customer $customer)
    {
        $this->assertVisible($request, $customer);
        $balances = [];
        foreach (['toman', 'dinar'] as $cur) {
            $invoiced = Money::of(Invoice::where('customer_id', $customer->id)->where('currency', $cur)->sum('total'));
            $paid = Money::of(CustomerPayment::where('customer_id', $customer->id)->where('currency', $cur)->sum('amount'));
            $balances[$cur] = Money::sub($invoiced, $paid);
        }

        return response()->json([
            'customer' => $customer,
            'invoices' => $customer->invoices()->latest()->limit(50)->get(),
            'payments' => $customer->payments()->latest('paid_at')->limit(50)->get(),
            'balances' => $balances,
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->assertVisible($request, $customer);
        if ($customer->archived_at) {
            throw new DomainException('مشتری بایگانی‌شده قابل ویرایش نیست');
        }
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'notes' => 'nullable|string',
        ]);
        $customer->update($validated);

        return response()->json($customer);
    }

    public function destroy(Request $request, Customer $customer)
    {
        $this->assertVisible($request, $customer);
        if ($customer->invoices()->exists() || $customer->payments()->exists()) {
            $customer->update(['archived_at' => now()]);

            return response()->json(['message' => 'مشتری بایگانی شد', 'archived' => true]);
        }
        $customer->delete();

        return response()->json(['message' => 'حذف شد']);
    }

    public function pay(Request $request, Customer $customer)
    {
        $this->assertVisible($request, $customer);
        $validated = $request->validate([
            'invoice_id' => 'nullable|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|in:toman,dinar',
            'method' => 'required|in:cash,card,bank_transfer,check',
            'notes' => 'nullable|string',
            'idempotency_key' => 'nullable|string|max:80',
        ]);

        return DB::transaction(function () use ($request, $customer, $validated) {
            if (!empty($validated['idempotency_key'])) {
                $existing = CustomerPayment::where('notes', 'idemp:'.$validated['idempotency_key'])->first();
                if ($existing) {
                    return response()->json($existing);
                }
            }

            $invoice = null;
            if (!empty($validated['invoice_id'])) {
                $invoice = Invoice::where('id', $validated['invoice_id'])->lockForUpdate()->firstOrFail();
                if ((int) $invoice->customer_id !== (int) $customer->id) {
                    throw new DomainException('فاکتور متعلق به این مشتری نیست');
                }
                if ($invoice->currency !== $validated['currency']) {
                    throw new DomainException('ارز پرداخت با فاکتور یکی نیست');
                }
                $paid = Money::of(CustomerPayment::where('invoice_id', $invoice->id)->sum('amount'));
                $outstanding = Money::sub($invoice->total, $paid);
                if (Money::cmp($validated['amount'], $outstanding) > 0) {
                    throw new DomainException('مبلغ پرداخت بیشتر از مانده فاکتور است', 422, [
                        'outstanding' => $outstanding,
                    ]);
                }
            }

            $payment = CustomerPayment::create([
                'customer_id' => $customer->id,
                'invoice_id' => $invoice?->id,
                'branch_id' => $invoice?->branch_id ?? $customer->branch_id,
                'user_id' => $request->user()->id,
                'amount' => $validated['amount'],
                'currency' => $validated['currency'],
                'method' => $validated['method'],
                'notes' => !empty($validated['idempotency_key'])
                    ? 'idemp:'.$validated['idempotency_key']
                    : ($validated['notes'] ?? null),
                'paid_at' => now(),
            ]);

            if ($invoice) {
                $paid = Money::of(CustomerPayment::where('invoice_id', $invoice->id)->sum('amount'));
                if (Money::cmp($paid, $invoice->total) >= 0) {
                    $invoice->update(['payment_status' => 'paid']);
                }
            }

            app(LedgerPoster::class)->postCustomerPayment($payment);

            return response()->json($payment, 201);
        });
    }

    private function assertVisible(Request $request, Customer $customer): void
    {
        $ids = BranchAccess::visibleBranchIds($request->user());
        if ($ids === null) {
            return;
        }
        if ($customer->branch_id && !in_array((int) $customer->branch_id, $ids, true)) {
            throw new DomainException('دسترسی غیرمجاز', 403);
        }
    }
}
