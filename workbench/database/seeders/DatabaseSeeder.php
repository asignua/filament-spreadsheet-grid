<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Product;
use Workbench\App\Models\Shelf;
use Workbench\App\Models\User;

/**
 * Demo data for `vendor/bin/testbench workbench:build`; log in at /admin as emma@example.com / password.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(['email' => 'emma@example.com'], ['name' => 'Emma Carter', 'password' => 'password', 'is_active' => true]);

        $shelf = Shelf::query()->create(['name' => 'Aisle 1']);

        for ($i = 1; $i <= 25; $i++) {
            Product::query()->create([
                'shelf_id' => $shelf->id,
                'name' => "Product {$i}",
                'sku' => sprintf('SKU-%03d', $i),
                'price' => $i * 3.5,
                'cost' => $i * 2,
                'stock' => $i * 4,
                'category' => $i % 2 === 0 ? 'Packaging' : 'Labels',
                'available' => $i % 5 !== 0,
            ]);
        }
    }
}
