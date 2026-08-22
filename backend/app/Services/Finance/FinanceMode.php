<?php

namespace App\Services\Finance;

/** V2 is the only active runtime. Dual posting mode is gone. */
class FinanceMode
{
    public function postingV2(): bool
    {
        return true;
    }
}
