<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\Book;
use App\Models\Inventory;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Branches ──────────────────────────────────────────────
        $qom = Branch::updateOrCreate(
            ['name' => 'دارالمناهل قم'],
            ['city' => 'قم', 'country' => 'ایران', 'type' => 'store', 'status' => 'active']
        );
        $mashhad = Branch::updateOrCreate(
            ['name' => 'دارالمناهل مشهد'],
            ['city' => 'مشهد', 'country' => 'ایران', 'type' => 'store', 'status' => 'active']
        );
        $iraq = Branch::updateOrCreate(
            ['name' => 'دارالمناهل عراق'],
            ['city' => 'نجف', 'country' => 'عراق', 'type' => 'store', 'status' => 'active']
        );
        $warehouse = Branch::updateOrCreate(
            ['name' => 'انبار مرکزی'],
            ['city' => 'قم', 'country' => 'ایران', 'type' => 'warehouse', 'status' => 'active']
        );

        // ── Users ─────────────────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'admin@almanahel.com'],
            [
                'name'      => 'مدیر کل',
                'password'  => Hash::make('password'),
                'role'      => 'super_admin',
                'branch_id' => $qom->id,
                'status'    => 'active',
                'iraq_only_visible_branches' => [$iraq->id],
            ]
        );

        User::updateOrCreate(
            ['email' => 'qom@almanahel.com'],
            [
                'name'      => 'مدیر قم',
                'password'  => Hash::make('password'),
                'role'      => 'branch_manager',
                'branch_id' => $qom->id,
                'status'    => 'active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'mashhad@almanahel.com'],
            [
                'name'      => 'مدیر مشهد',
                'password'  => Hash::make('password'),
                'role'      => 'branch_manager',
                'branch_id' => $mashhad->id,
                'status'    => 'active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'iraq@almanahel.com'],
            [
                'name'      => 'مدیر عراق',
                'password'  => Hash::make('password'),
                'role'      => 'branch_manager',
                'branch_id' => $iraq->id,
                'status'    => 'active',
                'iraq_only_visible_branches' => [$iraq->id],
            ]
        );

        User::updateOrCreate(
            ['email' => 'warehouse@almanahel.com'],
            [
                'name'      => 'انباردار',
                'password'  => Hash::make('password'),
                'role'      => 'warehouse_staff',
                'branch_id' => $warehouse->id,
                'status'    => 'active',
            ]
        );

        // ── Suppliers ─────────────────────────────────────────────
        $s1 = Supplier::updateOrCreate(
            ['name' => 'انتشارات الهادی'],
            ['phone' => '09121234567', 'type' => 'publisher']
        );
        $s2 = Supplier::updateOrCreate(
            ['name' => 'دارالنشر اسلامی'],
            ['phone' => '09131234567', 'type' => 'publisher']
        );
        Supplier::updateOrCreate(
            ['name' => 'آقای کریمی'],
            ['phone' => '09141234567', 'type' => 'individual']
        );

        // ── Books ─────────────────────────────────────────────────
        $books = [
            ['title' => 'مفاتیح الجنان', 'author' => 'شیخ عباس قمی', 'publisher' => 'الهادی', 'category' => 'دینی', 'iraq_only' => false, 'low_stock_threshold' => 5],
            ['title' => 'نهج البلاغه', 'author' => 'شریف رضی', 'publisher' => 'دارالنشر', 'category' => 'دینی', 'iraq_only' => false, 'low_stock_threshold' => 3],
            ['title' => 'دیوان حافظ', 'author' => 'شمس الدین حافظ', 'publisher' => 'مؤسسه', 'category' => 'ادبی', 'iraq_only' => false],
            ['title' => 'قرآن کریم (ترجمه الهی قمشه‌ای)', 'author' => '—', 'publisher' => 'الهادی', 'category' => 'دینی', 'iraq_only' => false],
            ['title' => 'صحیفه سجادیه', 'author' => 'امام سجاد (ع)', 'publisher' => 'دارالنشر', 'category' => 'دینی', 'iraq_only' => false, 'low_stock_threshold' => 2],
            ['title' => 'الفقه (موسوعة الفقه الإسلامي)', 'author' => 'السید الشیرازی', 'publisher' => 'مکتبة العراق', 'category' => 'فقه', 'iraq_only' => true],
            ['title' => 'موسوعة الإمام الخوئی', 'author' => 'الإمام الخوئی', 'publisher' => 'مکتبة النجف', 'category' => 'فقه', 'iraq_only' => true],
            ['title' => 'بوستان سعدی', 'author' => 'سعدی شیرازی', 'publisher' => 'مؤسسه', 'category' => 'ادبی', 'iraq_only' => false],
        ];

        foreach ($books as $b) {
            $book = Book::updateOrCreate(
                ['title' => $b['title'], 'author' => $b['author']],
                $b
            );

            if (!($b['iraq_only'] ?? false)) {
                Inventory::firstOrCreate(
                    ['branch_id' => $qom->id, 'book_id' => $book->id],
                    [
                        'quantity'         => rand(5, 80),
                        'type'             => 'consignment',
                        'supplier_id'      => $s1->id,
                        'price_toman'      => rand(80000, 500000),
                        'cost_price_toman' => rand(60000, 400000),
                    ]
                );
            }

            if ($b['iraq_only'] ?? false) {
                Inventory::firstOrCreate(
                    ['branch_id' => $iraq->id, 'book_id' => $book->id],
                    [
                        'quantity'         => rand(5, 30),
                        'type'             => 'consignment',
                        'supplier_id'      => $s2->id,
                        'price_dinar'      => rand(5000, 25000),
                        'cost_price_dinar' => rand(3000, 20000),
                    ]
                );
            }
        }
    }
}
