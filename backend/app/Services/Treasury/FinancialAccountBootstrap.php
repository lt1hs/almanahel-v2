<?php

namespace App\Services\Treasury;

use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Services\Ledger\SystemAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialAccountBootstrap
{
    /** @return list<string> */
    public const BRANCH_TYPES = [
        'cash_drawer',
        'bank',
        'card_clearing',
        'accounts_receivable',
        'checks_receivable',
        'checks_payable',
    ];

    /** @return list<string> */
    public const CORPORATE_TYPES = [
        'cash_drawer',
        'bank',
        'checks_payable',
    ];

    /**
     * @return array{planned: list<array<string, mixed>>, created: int, skipped: int, warnings: list<string>}
     */
    public function run(bool $apply): array
    {
        $planned = [];
        $warnings = [];
        $created = 0;
        $skipped = 0;

        if (!Schema::hasTable('financial_accounts')) {
            return [
                'planned' => [],
                'created' => 0,
                'skipped' => 0,
                'warnings' => ['missing table financial_accounts (2026_08_18 migrations not applied); dry-run cannot plan accounts'],
            ];
        }

        $scopes = $this->requiredScopes();
        foreach ($scopes as $scope) {
            $row = $this->planScope($scope);
            $planned[] = $row;
            if ($row['action'] === 'create_default') {
                if ($apply) {
                    $this->createDefault($scope);
                    $created++;
                }
            } elseif ($row['action'] === 'skip_exists') {
                $skipped++;
            } elseif (in_array($row['action'], ['missing_default', 'ambiguous'], true)) {
                $warnings[] = $row['message'];
            }
        }

        return compact('planned', 'created', 'skipped', 'warnings');
    }

    /**
     * @return list<array{branch_id: ?int, currency: string, type: string, label: string}>
     */
    private function requiredScopes(): array
    {
        $scopes = [];
        foreach (['toman', 'dinar'] as $currency) {
            foreach (self::CORPORATE_TYPES as $type) {
                $scopes[] = [
                    'branch_id' => null,
                    'currency' => $currency,
                    'type' => $type,
                    'label' => 'corporate',
                ];
            }
        }

        $branches = Branch::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get();

        foreach ($branches as $branch) {
            $currencies = $this->branchCurrencies($branch);
            foreach ($currencies as $currency) {
                foreach (self::BRANCH_TYPES as $type) {
                    $scopes[] = [
                        'branch_id' => (int) $branch->id,
                        'currency' => $currency,
                        'type' => $type,
                        'label' => (string) $branch->name,
                    ];
                }
            }
        }

        return $scopes;
    }

    /**
     * @return list<string>
     */
    private function branchCurrencies(Branch $branch): array
    {
        $out = [];
        if ($branch->supports_toman !== false) {
            $out[] = 'toman';
        }
        if ($branch->supports_dinar) {
            $out[] = 'dinar';
        }
        if ($out === []) {
            $out[] = 'toman';
        }

        return $out;
    }

    /**
     * @param  array{branch_id: ?int, currency: string, type: string, label: string}  $scope
     * @return array<string, mixed>
     */
    private function planScope(array $scope): array
    {
        $q = FinancialAccount::query()
            ->where('currency', $scope['currency'])
            ->where('type', $scope['type']);
        if ($scope['branch_id'] === null) {
            $q->whereNull('branch_id');
        } else {
            $q->where('branch_id', $scope['branch_id']);
        }
        $existing = $q->get();
        $defaults = $existing->where('is_default', true);
        $code = $this->code($scope);

        if ($defaults->count() === 1) {
            return [
                'action' => 'skip_exists',
                'code' => $defaults->first()->code,
                'branch_id' => $scope['branch_id'],
                'currency' => $scope['currency'],
                'type' => $scope['type'],
                'message' => 'default exists',
            ];
        }
        if ($defaults->count() > 1) {
            return [
                'action' => 'ambiguous',
                'code' => $code,
                'branch_id' => $scope['branch_id'],
                'currency' => $scope['currency'],
                'type' => $scope['type'],
                'message' => "ambiguous defaults for {$scope['label']} {$scope['currency']} {$scope['type']}",
            ];
        }
        if ($existing->isNotEmpty()) {
            return [
                'action' => 'missing_default',
                'code' => $code,
                'branch_id' => $scope['branch_id'],
                'currency' => $scope['currency'],
                'type' => $scope['type'],
                'message' => "missing default (accounts exist) for {$scope['label']} {$scope['currency']} {$scope['type']}",
            ];
        }

        return [
            'action' => 'create_default',
            'code' => $code,
            'branch_id' => $scope['branch_id'],
            'currency' => $scope['currency'],
            'type' => $scope['type'],
            'message' => 'will create default',
        ];
    }

    /**
     * @param  array{branch_id: ?int, currency: string, type: string}  $scope
     */
    private function createDefault(array $scope): void
    {
        DB::transaction(function () use ($scope) {
            $again = $this->planScope($scope);
            if ($again['action'] !== 'create_default') {
                return;
            }
            $ledger = SystemAccounts::get($scope['type'], $scope['currency']);
            $code = $this->code($scope);
            if (FinancialAccount::where('code', $code)->exists()) {
                return;
            }
            FinancialAccount::create([
                'code' => $code,
                'branch_id' => $scope['branch_id'],
                'currency' => $scope['currency'],
                'type' => $scope['type'],
                'name' => $scope['type'] . ' ' . $scope['currency'],
                'ledger_account_id' => $ledger->id,
                'is_default' => true,
                'is_active' => true,
            ]);
        });
    }

    /**
     * @param  array{branch_id: ?int, currency: string, type: string}  $scope
     */
    private function code(array $scope): string
    {
        $branch = $scope['branch_id'] === null ? 'corp' : 'b' . $scope['branch_id'];

        return $scope['type'] . '.' . $branch . '.' . $scope['currency'];
    }
}
