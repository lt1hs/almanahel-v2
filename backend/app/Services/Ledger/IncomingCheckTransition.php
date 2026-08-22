<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\Check;
use App\Models\User;
use App\Services\Receivables\InvoiceBalance;
use App\Support\Authorization\BranchAccess;
use Illuminate\Support\Facades\DB;

class IncomingCheckTransition
{
    public function __construct(
        private readonly FinancePostingGateway $gateway,
        private readonly InvoiceBalance $invoices,
    ) {
    }

    public function apply(Check $check, string $to, User $user, ?int $financialAccountId = null): Check
    {
        BranchAccess::assertCanMutateCheck($user, (int) $check->branch_id);

        return DB::transaction(function () use ($check, $to, $financialAccountId) {
            $check = Check::query()->whereKey($check->id)->lockForUpdate()->firstOrFail();
            if ($check->invoice_id) {
                \App\Models\Invoice::query()->whereKey($check->invoice_id)->lockForUpdate()->first();
            }
            $from = (string) $check->status;
            CheckLifecycle::assertIncoming($from, $to);
            if ($from === $to) {
                return $check->load(['invoice', 'branch']);
            }

            $updates = ['status' => $to];
            if ($to === 'cleared' && $check->cleared_at === null) {
                $updates['cleared_at'] = now();
            }
            if ($to === 'bounced' && $check->bounced_at === null) {
                $updates['bounced_at'] = now();
            }
            $check->update($updates);
            $check = $check->fresh();

            if ($to === 'cleared') {
                $this->gateway->incomingCheckCleared($check, $financialAccountId);
            }
            if ($to === 'bounced') {
                $this->gateway->incomingCheckBounced($check);
            }

            if ($check->invoice) {
                $this->invoices->refresh($check->invoice);
            }

            return $check->fresh()->load(['invoice', 'branch']);
        });
    }
}
