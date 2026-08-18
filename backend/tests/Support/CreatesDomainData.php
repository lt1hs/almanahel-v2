<?php

namespace Tests\Support;

use App\Models\Book;
use App\Models\Branch;
use App\Models\ConsignmentReceipt;
use App\Models\ConsignmentReceiptItem;
use App\Models\Inventory;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait CreatesDomainData
{
    protected function makeBranch(array $attrs = []): Branch
    {
        return Branch::create(array_merge([
            'name' => 'Test Store',
            'city' => 'قم',
            'country' => 'ایران',
            'type' => 'store',
            'status' => 'active',
        ], $attrs));
    }

    protected function makeUser(?Branch $branch = null, string $role = 'admin', array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'branch_id' => $branch?->id,
            'status' => 'active',
        ], $attrs));
    }

    protected function actingAsRole(string $role, ?Branch $branch = null, array $attrs = []): User
    {
        $user = $this->makeUser($branch, $role, $attrs);
        Sanctum::actingAs($user);
        return $user;
    }

    protected function makeBook(array $attrs = []): Book
    {
        return Book::create(array_merge([
            'title' => 'کتاب آزمایشی',
            'author' => 'نویسنده',
            'isbn' => '978' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT),
            'iraq_only' => false,
            'low_stock_threshold' => 5,
        ], $attrs));
    }

    protected function makeSupplier(array $attrs = []): Supplier
    {
        return Supplier::create(array_merge([
            'name' => 'ناشر آزمایشی',
            'type' => 'publisher',
            'status' => 'active',
        ], $attrs));
    }

    protected function makeInventory(Branch $branch, Book $book, array $attrs = []): Inventory
    {
        $inventory = Inventory::create(array_merge([
            'branch_id' => $branch->id,
            'book_id' => $book->id,
            'quantity' => 10,
            'type' => 'owned',
            'price_toman' => 100000,
            'price_dinar' => 2000,
            'cost_price_toman' => 70000,
            'cost_price_dinar' => 1400,
        ], $attrs));

        $qty = (int) $inventory->quantity;
        if ($qty > 0) {
            $currency = (!empty($attrs['cost_price_dinar']) && empty($attrs['cost_price_toman']))
                ? 'dinar'
                : 'toman';
            app(\App\Services\Stock\StockLotService::class)->createIntakeLot([
                'book_id' => $book->id,
                'branch_id' => $branch->id,
                'supplier_id' => $inventory->supplier_id,
                'ownership_type' => $inventory->type === 'consignment' ? 'consignment' : 'owned',
                'currency' => $currency,
                'unit_cost' => $currency === 'dinar'
                    ? ($inventory->cost_price_dinar ?? 0)
                    : ($inventory->cost_price_toman ?? 0),
                'quantity' => $qty,
                'origin' => app(\App\Services\Stock\StockLotService::class)->resolveOrigin($branch, (bool) $book->iraq_only),
                'legacy_inventory_id' => $inventory->id,
                'migration_source' => 'test_fixture',
            ], $inventory);
        }

        return $inventory->fresh();
    }

    protected function makeConsignmentReceipt(Branch $branch, Supplier $supplier, Book $book, array $itemAttrs = [], array $receiptAttrs = []): ConsignmentReceipt
    {
        $receipt = ConsignmentReceipt::create(array_merge([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'receipt_number' => 'CR-' . strtoupper(bin2hex(random_bytes(4))),
            'status' => 'unsettled',
            'currency' => 'toman',
            'total_value' => 0,
            'settled_amount' => 0,
            'received_at' => now()->toDateString(),
        ], $receiptAttrs));

        $qty = $itemAttrs['quantity_received'] ?? 10;
        $cost = $itemAttrs['cost_price'] ?? 70000;
        ConsignmentReceiptItem::create(array_merge([
            'consignment_receipt_id' => $receipt->id,
            'book_id' => $book->id,
            'quantity_received' => $qty,
            'quantity_sold' => 0,
            'quantity_returned' => 0,
            'cost_price' => $cost,
            'selling_price' => $itemAttrs['selling_price'] ?? 100000,
        ], $itemAttrs));

        $receipt->update(['total_value' => $qty * $cost]);

        return $receipt->fresh('items');
    }
}
