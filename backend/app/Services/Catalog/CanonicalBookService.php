<?php

namespace App\Services\Catalog;

use App\Models\Book;
use App\Models\User;
use App\Support\Authorization\BranchAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CanonicalBookService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Book, 1: bool} book and whether canonical ISBN was reused
     */
    public function findOrCreate(array $attributes, ?User $user = null): array
    {
        $isbn = isset($attributes['isbn']) ? trim((string) $attributes['isbn']) : null;
        if ($isbn === '') {
            $isbn = null;
            $attributes['isbn'] = null;
        }

        return DB::transaction(function () use ($attributes, $isbn, $user) {
            if ($isbn) {
                $existing = Book::query()->where('isbn', $isbn)->lockForUpdate()->first();
                if ($existing) {
                    if ($user) {
                        BranchAccess::assertIraqBookVisible($user, $existing);
                    }

                    return [$existing, true];
                }
            }

            try {
                return [Book::create($attributes), false];
            } catch (QueryException $e) {
                if ($isbn && $this->isDuplicateIsbn($e)) {
                    $winner = Book::query()->where('isbn', $isbn)->first();
                    if (!$winner) {
                        throw $e;
                    }
                    if ($user) {
                        BranchAccess::assertIraqBookVisible($user, $winner);
                    }

                    return [$winner, true];
                }

                throw $e;
            }
        });
    }

    private function isDuplicateIsbn(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate')
            && (str_contains($message, 'isbn') || str_contains($message, 'books_isbn'));
    }
}
