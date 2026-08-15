<?php

return [
    'low_stock_threshold' => (int) env('ALMANAHEL_LOW_STOCK_THRESHOLD', 5),
    'toman_to_dinar_rate' => (float) env('ALMANAHEL_TOMAN_TO_DINAR_RATE', 50),
];
