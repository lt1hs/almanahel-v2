<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use App\Services\Treasury\FinancialAccountBootstrap;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Core organizational data only. Operational data must start empty.
        $qom = Branch::updateOrCreate(
            ['name' => 'دارالمناهل قم'],
            [
                'city' => 'قم', 'country' => 'ایران', 'type' => 'store', 'status' => 'active',
                'is_central_warehouse' => false, 'is_intake_hub' => true, 'is_iraq_store' => false,
                'supports_toman' => true, 'supports_dinar' => false,
            ]
        );
        $mashhad = Branch::updateOrCreate(
            ['name' => 'دارالمناهل مشهد'],
            [
                'city' => 'مشهد', 'country' => 'ایران', 'type' => 'store', 'status' => 'active',
                'is_central_warehouse' => false, 'is_intake_hub' => false, 'is_iraq_store' => false,
                'supports_toman' => true, 'supports_dinar' => false,
            ]
        );
        $iraq = Branch::updateOrCreate(
            ['name' => 'دارالمناهل عراق'],
            [
                'city' => 'نجف', 'country' => 'عراق', 'type' => 'store', 'status' => 'active',
                'is_central_warehouse' => false, 'is_intake_hub' => false, 'is_iraq_store' => true,
                'supports_toman' => false, 'supports_dinar' => true,
            ]
        );
        $warehouse = Branch::updateOrCreate(
            ['name' => 'انبار مرکزی'],
            [
                'city' => 'قم', 'country' => 'ایران', 'type' => 'warehouse', 'status' => 'active',
                'is_central_warehouse' => true, 'is_intake_hub' => true, 'is_iraq_store' => false,
                'supports_toman' => true, 'supports_dinar' => false, 'can_sell' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@almanahel.com'],
            [
                'name' => 'مدیر کل',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'branch_id' => $qom->id,
                'status' => 'active',
                'iraq_only_visible_branches' => [$iraq->id],
            ]
        );

        User::updateOrCreate(
            ['email' => 'qom@almanahel.com'],
            [
                'name' => 'مدیر قم',
                'password' => Hash::make('password'),
                'role' => 'branch_manager',
                'branch_id' => $qom->id,
                'status' => 'active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'mashhad@almanahel.com'],
            [
                'name' => 'مدیر مشهد',
                'password' => Hash::make('password'),
                'role' => 'branch_manager',
                'branch_id' => $mashhad->id,
                'status' => 'active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'iraq@almanahel.com'],
            [
                'name' => 'مدیر عراق',
                'password' => Hash::make('password'),
                'role' => 'branch_manager',
                'branch_id' => $iraq->id,
                'status' => 'active',
                'iraq_only_visible_branches' => [$iraq->id],
            ]
        );

        User::updateOrCreate(
            ['email' => 'warehouse@almanahel.com'],
            [
                'name' => 'انباردار',
                'password' => Hash::make('password'),
                'role' => 'warehouse_staff',
                'branch_id' => $warehouse->id,
                'status' => 'active',
            ]
        );

        app(FinancialAccountBootstrap::class)->run(true);
    }
}
