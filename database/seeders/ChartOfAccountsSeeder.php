<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Standard double-entry chart of accounts.
     * Codes are stable system identifiers used by the AccountingService.
     */
    public function run(): void
    {
        $accounts = [
            // Assets
            ['code' => '1000', 'name' => 'Cash on Hand', 'type' => 'asset', 'subtype' => 'cash'],
            ['code' => '1100', 'name' => 'Bank Accounts', 'type' => 'asset', 'subtype' => 'bank'],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'receivable'],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'inventory'],
            // Liabilities
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'payable'],
            ['code' => '2100', 'name' => 'Tax Payable', 'type' => 'liability', 'subtype' => 'tax_payable'],
            ['code' => '2200', 'name' => 'Customer Deposits', 'type' => 'liability', 'subtype' => 'deposit'],
            // Equity
            ['code' => '3000', 'name' => 'Owner\'s Capital', 'type' => 'equity', 'subtype' => 'capital'],
            ['code' => '3100', 'name' => 'Retained Earnings', 'type' => 'equity', 'subtype' => 'retained'],
            // Revenue
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'sales'],
            ['code' => '4100', 'name' => 'Service Revenue', 'type' => 'revenue', 'subtype' => 'service'],
            ['code' => '4200', 'name' => 'Sales Discounts', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            // Expenses
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cogs'],
            ['code' => '6000', 'name' => 'Operating Expenses', 'type' => 'expense', 'subtype' => 'operating'],
            ['code' => '6100', 'name' => 'Salaries & Wages', 'type' => 'expense', 'subtype' => 'salary'],
            ['code' => '6200', 'name' => 'Rent', 'type' => 'expense', 'subtype' => 'rent'],
            ['code' => '6300', 'name' => 'Utilities', 'type' => 'expense', 'subtype' => 'utility'],
        ];

        foreach ($accounts as $account) {
            Account::updateOrCreate(
                ['code' => $account['code']],
                array_merge($account, ['is_system' => true, 'is_active' => true])
            );
        }
    }
}
