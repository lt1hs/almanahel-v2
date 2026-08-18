<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => 'Branch ' . fake()->unique()->numerify('###'),
            'city' => 'قم',
            'country' => 'ایران',
            'type' => 'store',
            'status' => 'active',
        ];
    }
}
