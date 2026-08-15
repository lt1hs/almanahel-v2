<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BookCategoryController extends Controller
{
    private function isAdmin($user): bool
    {
        return in_array($user?->role, ['super_admin', 'admin'], true);
    }

    private function assertAdmin($user): void
    {
        if (!$this->isAdmin($user)) {
            abort(403, 'فقط مدیر می‌تواند دسته‌بندی‌ها را مدیریت کند');
        }
    }

    /** @return string[] */
    private function defaults(): array
    {
        return array_values(array_filter(config('almanahel.book_categories', [])));
    }

    /** @return string[] */
    private function storedList(): array
    {
        $cached = Cache::get('almanahel.book_categories');
        if (is_array($cached) && count($cached) > 0) {
            return $this->normalizeList($cached);
        }

        $fromBooks = Book::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();

        $merged = $this->normalizeList(array_merge($this->defaults(), $fromBooks));
        Cache::forever('almanahel.book_categories', $merged);

        return $merged;
    }

    /** @param array<int, mixed> $list @return string[] */
    private function normalizeList(array $list): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $item) {
            $name = trim((string) $item);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $name;
        }

        return array_values($out);
    }

    private function persist(array $list): array
    {
        $normalized = $this->normalizeList($list);
        Cache::forever('almanahel.book_categories', $normalized);

        return $normalized;
    }

    private function usageCounts(array $categories): array
    {
        if (!$categories) {
            return [];
        }

        $counts = Book::query()
            ->select('category', DB::raw('COUNT(*) as total'))
            ->whereIn('category', $categories)
            ->groupBy('category')
            ->pluck('total', 'category')
            ->all();

        $result = [];
        foreach ($categories as $name) {
            $result[$name] = (int) ($counts[$name] ?? 0);
        }

        return $result;
    }

    public function index()
    {
        $categories = $this->storedList();
        $usage = $this->usageCounts($categories);

        return response()->json([
            'categories' => array_map(
                fn ($name) => [
                    'name' => $name,
                    'books_count' => $usage[$name] ?? 0,
                ],
                $categories
            ),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertAdmin($request->user());

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $name = trim($validated['name']);
        $list = $this->storedList();
        foreach ($list as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($name)) {
                return response()->json(['message' => 'این دسته‌بندی از قبل وجود دارد'], 422);
            }
        }

        $list[] = $name;
        $categories = $this->persist($list);

        return response()->json([
            'categories' => array_map(
                fn ($n) => ['name' => $n, 'books_count' => $this->usageCounts([$n])[$n] ?? 0],
                $categories
            ),
            'created' => $name,
        ], 201);
    }

    public function rename(Request $request)
    {
        $this->assertAdmin($request->user());

        $validated = $request->validate([
            'from' => 'required|string|max:100',
            'to'   => 'required|string|max:100|different:from',
        ]);

        $from = trim($validated['from']);
        $to = trim($validated['to']);
        $list = $this->storedList();

        if (!in_array($from, $list, true)) {
            return response()->json(['message' => 'دسته‌بندی یافت نشد'], 404);
        }

        foreach ($list as $existing) {
            if ($existing !== $from && mb_strtolower($existing) === mb_strtolower($to)) {
                return response()->json(['message' => 'نام جدید با دسته دیگری تداخل دارد'], 422);
            }
        }

        $list = array_map(fn ($n) => $n === $from ? $to : $n, $list);
        $categories = $this->persist($list);

        Book::where('category', $from)->update(['category' => $to]);

        return response()->json([
            'categories' => array_map(
                fn ($n) => ['name' => $n, 'books_count' => $this->usageCounts([$n])[$n] ?? 0],
                $categories
            ),
            'renamed' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function destroy(Request $request, string $category)
    {
        $this->assertAdmin($request->user());

        $name = trim(urldecode($category));
        $list = $this->storedList();

        if (!in_array($name, $list, true)) {
            return response()->json(['message' => 'دسته‌بندی یافت نشد'], 404);
        }

        $inUse = (int) Book::where('category', $name)->count();
        $clearBooks = $request->boolean('clear_books');

        if ($inUse > 0 && !$clearBooks) {
            return response()->json([
                'message' => "این دسته روی {$inUse} کتاب استفاده شده است. برای حذف، clear_books=1 بفرستید.",
                'books_count' => $inUse,
            ], 422);
        }

        if ($inUse > 0 && $clearBooks) {
            Book::where('category', $name)->update(['category' => null]);
        }

        $list = array_values(array_filter($list, fn ($n) => $n !== $name));
        $categories = $this->persist($list);

        return response()->json([
            'categories' => array_map(
                fn ($n) => ['name' => $n, 'books_count' => $this->usageCounts([$n])[$n] ?? 0],
                $categories
            ),
            'deleted' => $name,
            'cleared_books' => $clearBooks ? $inUse : 0,
        ]);
    }
}
