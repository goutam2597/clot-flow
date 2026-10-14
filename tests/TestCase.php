<?php

namespace Tests;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * Build a complete, coherent business: roles, permissions, the chart of
     * accounts, languages, currency, tax rates, payment methods, one branch
     * with its warehouse and till, and an owner account.
     *
     * Returns the owner user; callers sign in as it when they need every screen.
     */
    protected function bootBusiness(): Business
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        return Business::create();
    }

    /**
     * Shorthand: a fully booted business plus an authenticated owner.
     */
    protected function actingAsOwner(): User
    {
        $business = $this->bootBusiness();

        $this->actingAs($business->owner);

        return $business->owner;
    }
}
