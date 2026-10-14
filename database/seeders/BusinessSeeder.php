<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Language;
use App\Models\PaymentMethod;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        // --- Language ---
        $english = Language::firstOrCreate(
            ['code' => 'en'],
            ['name' => 'English', 'native_name' => 'English', 'locale' => 'en', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]
        );
        Language::firstOrCreate(
            ['code' => 'bn'],
            ['name' => 'Bengali', 'native_name' => 'বাংলা', 'locale' => 'bn', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]
        );
        Language::firstOrCreate(
            ['code' => 'ar'],
            ['name' => 'Arabic', 'native_name' => 'العربية', 'locale' => 'ar', 'direction' => 'rtl', 'is_default' => false, 'is_active' => true, 'sort_order' => 3]
        );

        // --- Currency ---
        Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'symbol_position' => 'before', 'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1, 'is_active' => true, 'is_default' => true]
        );

        // --- Tax rates ---
        TaxRate::firstOrCreate(['code' => 'VAT15'], ['name' => 'VAT 15%', 'rate' => 15, 'type' => 'exclusive', 'is_active' => true]);
        TaxRate::firstOrCreate(['code' => 'ZERO'], ['name' => 'Zero Rated', 'rate' => 0, 'type' => 'exclusive', 'is_active' => true]);

        // --- Payment methods ---
        $methods = [
            ['name' => 'Cash', 'code' => 'cash', 'type' => 'cash', 'requires_reference' => false, 'sort_order' => 1],
            ['name' => 'Card', 'code' => 'card', 'type' => 'card', 'requires_reference' => true, 'sort_order' => 2],
            ['name' => 'Bank Transfer', 'code' => 'bank', 'type' => 'bank', 'requires_reference' => true, 'sort_order' => 3],
            ['name' => 'Mobile Payment', 'code' => 'mobile', 'type' => 'mobile', 'requires_reference' => true, 'sort_order' => 4],
        ];
        foreach ($methods as $m) {
            PaymentMethod::firstOrCreate(['code' => $m['code']], $m);
        }

        // --- Branch, Warehouse, Register ---
        $branch = Branch::firstOrCreate(
            ['code' => 'HQ'],
            ['name' => 'Head Office', 'is_active' => true, 'is_default' => true, 'country' => 'US']
        );
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'MAIN'],
            ['branch_id' => $branch->id, 'name' => 'Main Warehouse', 'is_active' => true, 'is_default' => true]
        );
        CashRegister::firstOrCreate(
            ['code' => 'REG01'],
            ['branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'name' => 'Counter 1', 'is_active' => true]
        );

        // NOTE: The double-entry chart of accounts is seeded by ChartOfAccountsSeeder
        // so that account codes stay in sync with AccountingService system lookups.

        // --- Admin user ---
        $admin = User::firstOrCreate(
            ['email' => 'admin@meridian.test'],
            ['name' => 'System Administrator', 'password' => Hash::make('password'), 'status' => 'active', 'branch_id' => $branch->id]
        );
        if (method_exists($admin, 'assignRole') && Role::where('name', 'Business Owner')->exists()) {
            $admin->assignRole('Business Owner');
        }
    }
}
