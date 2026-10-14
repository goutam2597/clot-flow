<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // The permission package touches the cache store during seeding. In this
        // environment the cache table may not exist yet, so flush defensively.
        try {
            if (Schema::hasTable('cache')) {
                Cache::flush();
            }
        } catch (\Throwable $e) {
            // Cache store unavailable — safe to continue.
        }

        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // Dashboard
            'dashboard.view',
            // POS & Sales
            'pos.access',
            'sales.view', 'sales.create', 'sales.void', 'sales.refund',
            // Products
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.manage',
            // Inventory
            'inventory.view', 'inventory.adjust', 'inventory.transfer',
            // Purchases
            'purchases.view', 'purchases.create', 'purchases.receive',
            // Customers & Suppliers
            'customers.view', 'customers.manage',
            'suppliers.view', 'suppliers.manage',
            // Invoices
            'invoices.view', 'invoices.create',
            // Expenses
            'expenses.view', 'expenses.manage',
            // Accounting
            'accounting.view', 'accounting.manage',
            // Reports
            'reports.view',
            // Administration
            'branches.manage',
            'users.manage',
            'roles.manage',
            'settings.manage',
            'languages.manage',
            'audit.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Define roles
        $roles = [
            'Business Owner' => $permissions,
            'Administrator' => $permissions,
            'Manager' => [
                'dashboard.view', 'pos.access', 'sales.view', 'sales.create', 'sales.void', 'sales.refund',
                'products.view', 'products.create', 'products.update', 'products.manage',
                'inventory.view', 'inventory.adjust', 'inventory.transfer',
                'purchases.view', 'purchases.create', 'purchases.receive',
                'customers.view', 'customers.manage', 'suppliers.view', 'suppliers.manage',
                'invoices.view', 'invoices.create', 'expenses.view', 'expenses.manage',
                'reports.view', 'audit.view',
            ],
            'Cashier' => [
                'dashboard.view', 'pos.access', 'sales.view', 'sales.create', 'sales.refund',
                'customers.view', 'invoices.view',
            ],
            'Inventory Manager' => [
                'dashboard.view', 'products.view', 'products.create', 'products.update', 'products.manage',
                'inventory.view', 'inventory.adjust', 'inventory.transfer',
                'purchases.view', 'purchases.create', 'purchases.receive',
                'suppliers.view', 'reports.view',
            ],
            'Accountant' => [
                'dashboard.view', 'sales.view', 'purchases.view',
                'invoices.view', 'invoices.create',
                'expenses.view', 'expenses.manage',
                'accounting.view', 'accounting.manage',
                'customers.view', 'suppliers.view', 'reports.view', 'audit.view',
            ],
            'Purchasing Manager' => [
                'dashboard.view', 'purchases.view', 'purchases.create', 'purchases.receive',
                'products.view', 'inventory.view', 'suppliers.view', 'suppliers.manage',
                'reports.view',
            ],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
