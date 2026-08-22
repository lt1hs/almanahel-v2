<?php

namespace Tests\Unit;

use App\Support\ConsignmentSettleLink;
use PHPUnit\Framework\TestCase;

class ConsignmentSettleLinkTest extends TestCase
{
    public function test_missing_supplier_account_id_returns_null_link(): void
    {
        $this->assertNull(ConsignmentSettleLink::build(42, null));
        $this->assertNull(ConsignmentSettleLink::build(42, 0));
    }

    public function test_link_uses_only_supplier_account_id(): void
    {
        $href = ConsignmentSettleLink::build(42, 99);
        $this->assertNotNull($href);
        $this->assertStringContainsString('receipt_id=42', $href);
        $this->assertStringContainsString('supplier_account_id=99', $href);
    }

    public function test_canonical_supplier_id_must_not_substitute_for_account_id(): void
    {
        $this->assertTrue(ConsignmentSettleLink::mustNotSubstituteCanonicalSupplierId(null, 7));
        $this->assertTrue(ConsignmentSettleLink::mustNotSubstituteCanonicalSupplierId(5, 7));
        $this->assertSame(
            ConsignmentSettleLink::build(1, 5),
            ConsignmentSettleLink::build(1, 5)
        );
        $this->assertNotSame(
            ConsignmentSettleLink::build(1, 5),
            ConsignmentSettleLink::build(1, 7)
        );
    }
}
