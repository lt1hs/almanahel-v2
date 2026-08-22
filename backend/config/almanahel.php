<?php

return [
    'low_stock_threshold' => (int) env('ALMANAHEL_LOW_STOCK_THRESHOLD', 5),
    'toman_to_dinar_rate' => (float) env('ALMANAHEL_TOMAN_TO_DINAR_RATE', 50),
    /** Store share of consignment sold cost; publisher is owed (1 − rate). Live HTTP still uses this until T9. */
    'consignment_commission_rate' => (float) env('ALMANAHEL_CONSIGNMENT_COMMISSION_RATE', 0.1),
    'finance_ledger_reports_enabled' => (bool) env('FINANCE_LEDGER_REPORTS_ENABLED', true),
    /**
     * Ignored at runtime. FinanceMode is permanently V2; LedgerPoster is unused.
     * Kept so old env files do not switch the accounting engine.
     */
    'finance_posting_v2_enabled' => true,
    'consignment_payable' => [
        'default_basis' => env('ALMANAHEL_CONSIGNMENT_PAYABLE_BASIS', 'full_unit_cost'),
        'default_rate' => env('ALMANAHEL_CONSIGNMENT_PAYABLE_RATE', '1.0'),
        /** Audit metadata only; does not switch payable calculation. */
        'effective_at' => env('ALMANAHEL_CONSIGNMENT_PAYABLE_EFFECTIVE_AT'),
    ],
    /** Branch managers may sell above list price when true. */
    'allow_branch_price_override' => (bool) env('ALMANAHEL_ALLOW_BRANCH_PRICE_OVERRIDE', true),
    'book_categories' => [
        'دینی',
        'ادبی',
        'فقه',
        'عمومی',
        'کودک',
        'تاریخ',
    ],
];
