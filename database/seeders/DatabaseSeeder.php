<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            BusinessSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);
    }

    /**
     * A complete, trading business for evaluation and screenshots.
     *
     * Run explicitly with `--class=DemoSeeder` so a production install never
     * picks up demo customers, sales and suppliers by accident.
     *
     * The 130-product supermarket catalogue is layered on top of the small
     * demo assortment. Both run: the fifteen hand-written products have prices
     * and margins tuned for the accounting examples, while the imported
     * catalogue gives the POS grid the density a real shop has.
     */
    public function demo(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            BusinessSeeder::class,
            ChartOfAccountsSeeder::class,

            // The catalogue runs BEFORE the trading history. DemoSeeder sells
            // real products, so there have to be some first — and the order is
            // safe because BusinessSeeder has already created the warehouse
            // and the tax rates the catalogue attaches to.
            CatalogSeeder::class,
            DemoSeeder::class,
        ]);
    }
}

