<?php

namespace Tests;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Language;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Database\Factories\ProductFactory;
use Spatie\Permission\Models\Role;

/**
 * A small, internally consistent business used across the test suite.
 *
 * Building it in one place keeps tests focused on behaviour rather than on
 * re-declaring the same branch / warehouse / till / chart-of-accounts fixture in
 * every file.
 */
final class Business
{
    public Branch $branch;

    public Warehouse $warehouse;

    public CashRegister $register;

    public User $owner;

    public Currency $currency;

    public TaxRate $taxRate;

    public PaymentMethod $cashMethod;

    public PaymentMethod $cardMethod;

    public static function create(): self
    {
        $business = new self();

        Language::firstOrCreate(
            ['code' => 'en'],
            ['name' => 'English', 'native_name' => 'English', 'locale' => 'en', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]
        );

        $business->currency = Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'symbol_position' => 'before',
                'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
                'is_active' => true, 'is_default' => true]
        );

        $business->taxRate = TaxRate::firstOrCreate(
            ['code' => 'VAT15'],
            ['name' => 'VAT 15%', 'rate' => 15, 'type' => 'exclusive', 'is_active' => true]
        );

        TaxRate::firstOrCreate(
            ['code' => 'ZERO'],
            ['name' => 'Zero Rated', 'rate' => 0, 'type' => 'exclusive', 'is_active' => true]
        );

        $business->cashMethod = PaymentMethod::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash', 'type' => 'cash', 'requires_reference' => false, 'is_active' => true, 'sort_order' => 1]
        );
        $business->cardMethod = PaymentMethod::firstOrCreate(
            ['code' => 'card'],
            ['name' => 'Card', 'type' => 'card', 'requires_reference' => true, 'is_active' => true, 'sort_order' => 2]
        );

        $business->branch = Branch::create([
            'name' => 'Head Office',
            'code' => 'HQ',
            'is_active' => true,
            'is_default' => true,
        ]);

        $business->warehouse = Warehouse::create([
            'branch_id' => $business->branch->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);

        $business->register = CashRegister::create([
            'branch_id' => $business->branch->id,
            'warehouse_id' => $business->warehouse->id,
            'name' => 'Counter 1',
            'code' => 'REG01',
            'is_active' => true,
        ]);

        $business->owner = User::factory()->create([
            'name' => 'System Administrator',
            'email' => 'owner@example.test',
            'branch_id' => $business->branch->id,
        ]);
        $business->owner->assignRole(self::role('Business Owner'));

        return $business;
    }

    /**
     * Create a staff account holding a single role.
     */
    public function staffWithRole(string $roleName, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
        ], $attributes));

        $user->assignRole($this->role($roleName));

        return $user;
    }

    /**
     * A stock-tracked product with stock already on hand.
     */
    public function productInStock(float $quantity = 100, float $cost = 10.0, float $price = 15.0): Product
    {
        $product = Product::factory()
            ->priced($cost, $price)
            ->create(['tax_rate_id' => $this->taxRate->id]);

        $product->stocks()->create([
            'warehouse_id' => $this->warehouse->id,
            'quantity' => number_format($quantity, 4, '.', ''),
            'avg_cost' => number_format($cost, 4, '.', ''),
        ]);

        return $product->refresh();
    }

    private static function role(string $name): Role
    {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

        if ($name === 'Cashier') {
            // Guard against a seeder that has not run in this particular test.
            $role->syncPermissions([
                'dashboard.view', 'pos.access', 'sales.view', 'sales.create',
                'customers.view', 'invoices.view',
            ]);
        }

        return $role;
    }
}
