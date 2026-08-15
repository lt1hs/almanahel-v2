<?php

return [
    'low_stock_threshold' => (int) env('ALMANAHEL_LOW_STOCK_THRESHOLD', 5),
    'toman_to_dinar_rate' => (float) env('ALMANAHEL_TOMAN_TO_DINAR_RATE', 50),
    /** Store share of consignment sold cost; publisher is owed (1 − rate). */
    'consignment_commission_rate' => (float) env('ALMANAHEL_CONSIGNMENT_COMMISSION_RATE', 0.1),
    'book_categories' => [
        'دینی',
        'ادبی',
        'فقه',
        'عمومی',
        'کودک',
        'تاریخ',
    ],
];
