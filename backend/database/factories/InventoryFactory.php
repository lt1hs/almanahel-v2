<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\Branch;
use App\Models\Inventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inventory> */
class InventoryFactory extends Factory
{
    protected $model = Inventory::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'book_id' => Book::factory(),
            'quantity' => 10,
            'type' => 'owned',
            'supplier_id' => null,
            'price_toman' => 100000,
            'price_dinar' => 2000,
            'cost_price_toman' => 70000,
            'cost_price_dinar' => 1400,
        ];
    }
}
