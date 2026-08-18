<?php

namespace Database\Factories;

use App\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Book> */
class BookFactory extends Factory
{
    protected $model = Book::class;

    public function definition(): array
    {
        return [
            'isbn' => fake()->unique()->numerify('978##########'),
            'title' => 'Book ' . fake()->words(2, true),
            'author' => fake()->name(),
            'publisher' => 'Publisher',
            'category' => 'عمومی',
            'iraq_only' => false,
            'low_stock_threshold' => 5,
        ];
    }
}
