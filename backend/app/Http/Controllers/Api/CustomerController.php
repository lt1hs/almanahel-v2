<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Ledger\FinancePostingGateway;
use App\Services\Receivables\CustomerBalances;
use App\Services\Receivables\InvoiceBalance;
use App\Support\Authorization\BranchAccess;
use App\Support\Money;
use App\Support\ActivityLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        if ($request->filled('branch_id')) {
            BranchAccess::assertBranchAllowed($request->user(), (int) $request->branch_id);
            $branchId = (int) $request->branch_id;
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
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

        ActivityLogger::record(
            'customers',
            'created',
            "ایجاد مشتری «{$customer->name}»",
            $customer,
            ['after' => $customer->only(['name', 'phone', 'branch_id'])],
            $customer->branch_id ? (int) $customer->branch_id : null,
        );

        return response()->json($customer, 201);
    }

    public function show(Request $request, Customer $customer)
    {
        $this->assertVisible($request, $customer);

        return response()->json([
            'customer' => $customer,
            'invoices' => $customer->invoices()->latest()->limit(50)->get(),
            'payments' => $customer->payments()->latest('paid_at')->limit(50)->get(),
            'balances' => app(CustomerBalances::class)->forCustomer($customer),
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
        $before = $customer->only(array_keys($validated));
        $customer->update($validated);

        ActivityLogger::record(
            'customers',
            'updated',
            "ویرایش مشتری «{$customer->name}»",
            $customer,
            [
                'before' => $before,
                'after' => $customer->only(array_keys($validated)),
                'changed_fields' => array_keys($customer->getChanges()),
            ],
            $customer->branch_id ? (int) $customer->branch_id : null,
        );

        return response()->json($customer);
    }

    public function destroy(Request $request, Customer $customer)
    {
        $this->assertVisible($request, $customer);
        if ($customer->invoices()->exists() || $customer->payments()->exists()) {
            $customer->update(['archived_at' => now()]);

            ActivityLogger::record(
                'customers',
                'archived',
                "بایگانی مشتری «{$customer->name}»",
                $customer,
                ['archived_at' => (string) $customer->archived_at],
                $customer->branch_id ? (int) $customer->branch_id : null,
                null,
                'warning',
            );

            return response()->json(['message' => 'مشتری بایگانی شد', 'archived' => true]);
        }
        $snapshot = $customer->only(['id', 'name', 'phone', 'branch_id']);
        $customer->delete();

        ActivityLogger::record(
            'customers',
            'deleted',
            "حذف مشتری «{$snapshot['name']}»",
            null,
            [...$snapshot, 'subject_type' => 'customer', 'subject_id' => $snapshot['id']],
            $snapshot['branch_id'] ? (int) $snapshot['branch_id'] : null,
        );

        return response()->json(['message' => 'حذف شد']);
    }

    public function pay(Request $request, Customer $customer)
    {
        $this->assertVisible($request, $customer);
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|in:toman,dinar',
            'method' => 'required|in:cash,card,bank_transfer,check',
            'notes' => 'nullable|string',
            'idempotency_key' => 'nullable|string|max:80',
            'financial_account_id' => 'nullable|exists:financial_accounts,id',
        ]);

        if ($validated['method'] === 'check') {
            throw new DomainException(
                'پرداخت با چک از این مسیر مجاز نیست. از گردش چک فاکتور استفاده کنید.',
                422
            );
        }

        return DB::transaction(function () use ($request, $customer, $validated) {
            $payloadHash = hash('sha256', json_encode([
                'customer_id' => (int) $customer->id,
                'invoice_id' => $validated['invoice_id'],
                'amount' => Money::of($validated['amount']),
                'currency' => $validated['currency'],
                'method' => $validated['method'],
            ], JSON_THROW_ON_ERROR));

            if (!empty($validated['idempotency_key'])) {
                $existing = CustomerPayment::query()
                    ->where('idempotency_key', $validated['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ((string) $existing->payload_hash !== $payloadHash) {
                        throw new DomainException('کلید تکرار با محتوای متفاوت قبلاً استفاده شده است', 409);
                    }

                    return response()->json($existing);
                }
            }

            $invoice = Invoice::where('id', $validated['invoice_id'])->lockForUpdate()->firstOrFail();
            if ((int) $invoice->customer_id !== (int) $customer->id) {
                throw new DomainException('فاکتور متعلق به این مشتری نیست');
            }
            BranchAccess::assertCanMutateInBranch($request->user(), (int) $invoice->branch_id);
            if ($invoice->currency !== $validated['currency']) {
                throw new DomainException('ارز پرداخت با فاکتور یکی نیست');
            }
            if (in_array((string) $invoice->payment_method, ['cash', 'card'], true)) {
                throw new DomainException('فاکتور نقدی یا کارتی مانده دریافتنی ندارد', 422);
            }
            $balances = app(InvoiceBalance::class);
            if (!$balances->carriesOpenReceivable($invoice)) {
                throw new DomainException('این فاکتور دریافتنی باز ندارد', 422);
            }
            $outstanding = $balances->outstanding($invoice);
            if (Money::cmp($validated['amount'], $outstanding) > 0) {
                throw new DomainException('مبلغ پرداخت بیشتر از مانده فاکتور است', 422, [
                    'outstanding' => $outstanding,
                ]);
            }

            try {
                $payment = CustomerPayment::create([
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice->id,
                    'branch_id' => $invoice->branch_id,
                    'user_id' => $request->user()->id,
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'method' => $validated['method'],
                    'notes' => $validated['notes'] ?? null,
                    'paid_at' => now(),
                    'financial_account_id' => $validated['financial_account_id'] ?? null,
                    'idempotency_key' => $validated['idempotency_key'] ?? null,
                    'payload_hash' => $payloadHash,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $existing = CustomerPayment::query()
                    ->where('idempotency_key', $validated['idempotency_key'] ?? '')
                    ->first();
                if ($existing && (string) $existing->payload_hash === $payloadHash) {
                    return response()->json($existing);
                }
                throw new DomainException('کلید تکرار با محتوای متفاوت قبلاً استفاده شده است', 409);
            }

            app(InvoiceBalance::class)->refresh($invoice);

            app(FinancePostingGateway::class)->customerPayment(
                $payment,
                $validated['financial_account_id'] ?? null
            );

            app(InvoiceBalance::class)->refresh($invoice);

            ActivityLogger::record(
                'customers',
                'paid',
                "دریافت وجه از مشتری «{$customer->name}» برای فاکتور {$invoice->invoice_number}",
                $payment,
                [
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => Money::of($payment->amount),
                    'currency' => $payment->currency,
                    'method' => $payment->method,
                ],
                (int) $invoice->branch_id,
            );

            return response()->json($payment->fresh(), 201);
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
