<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Currency;
use App\Models\ExpenseCategory;
use App\Models\Language;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * A small but complete trading business, so the application can be evaluated
 * with real data instead of empty screens.
 *
 * Everything is written through the same services the UI uses, so the seeded
 * ledger, stock movements and audit trail are exactly what a day of real
 * trading would produce.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Languages -----------------------------------------------------
        Language::firstOrCreate(
            ['code' => 'en'],
            ['name' => 'English', 'native_name' => 'English', 'locale' => 'en', 'direction' => 'ltr',
                'is_default' => true, 'is_active' => true, 'sort_order' => 1]
        );
        Language::firstOrCreate(
            ['code' => 'bn'],
            ['name' => 'Bengali', 'native_name' => 'বাংলা', 'locale' => 'bn', 'direction' => 'ltr',
                'is_default' => false, 'is_active' => true, 'sort_order' => 2]
        );
        Language::firstOrCreate(
            ['code' => 'ar'],
            ['name' => 'Arabic', 'native_name' => 'العربية', 'locale' => 'ar', 'direction' => 'rtl',
                'is_default' => false, 'is_active' => true, 'sort_order' => 3]
        );

        // ---- Currency ------------------------------------------------------
        Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'symbol_position' => 'before',
                'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
                'is_active' => true, 'is_default' => true]
        );

        // ---- Tax -----------------------------------------------------------
        $vat = TaxRate::firstOrCreate(
            ['code' => 'VAT15'],
            ['name' => 'VAT 15%', 'rate' => 15, 'type' => 'exclusive', 'is_active' => true]
        );
        TaxRate::firstOrCreate(
            ['code' => 'REDUCED5'],
            ['name' => 'Reduced 5%', 'rate' => 5, 'type' => 'exclusive', 'is_active' => true]
        );
        TaxRate::firstOrCreate(
            ['code' => 'ZERO'],
            ['name' => 'Zero Rated', 'rate' => 0, 'type' => 'exclusive', 'is_active' => true]
        );

        // ---- Payment methods ----------------------------------------------
        foreach ([
            ['name' => 'Cash', 'code' => 'cash', 'type' => 'cash', 'requires_reference' => false, 'sort_order' => 1],
            ['name' => 'Card', 'code' => 'card', 'type' => 'card', 'requires_reference' => true, 'sort_order' => 2],
            ['name' => 'Bank Transfer', 'code' => 'bank', 'type' => 'bank', 'requires_reference' => true, 'sort_order' => 3],
            ['name' => 'Mobile Payment', 'code' => 'mobile', 'type' => 'mobile', 'requires_reference' => true, 'sort_order' => 4],
        ] as $method) {
            PaymentMethod::firstOrCreate(['code' => $method['code']], $method + ['is_active' => true]);
        }

        // ---- Branches, warehouses, registers -------------------------------
        $hq = Branch::firstOrCreate(
            ['code' => 'HQ'],
            ['name' => 'Head Office', 'is_active' => true, 'is_default' => true,
                'phone' => '+1 555 0100', 'email' => 'store@example.test',
                'address_line' => '1 Market Street', 'city' => 'Springfield', 'country' => 'United States']
        );

        $second = Branch::firstOrCreate(
            ['code' => 'BR2'],
            ['name' => 'Riverside Branch', 'is_active' => true, 'is_default' => false,
                'phone' => '+1 555 0200', 'city' => 'Riverside', 'country' => 'United States']
        );

        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'MAIN'],
            ['branch_id' => $hq->id, 'name' => 'Main Warehouse', 'is_active' => true, 'is_default' => true]
        );
        Warehouse::firstOrCreate(
            ['code' => 'BR2-WH'],
            ['branch_id' => $second->id, 'name' => 'Riverside Store', 'is_active' => true, 'is_default' => true]
        );

        $register = CashRegister::firstOrCreate(
            ['code' => 'REG01'],
            ['branch_id' => $hq->id, 'warehouse_id' => $warehouse->id,
                'name' => 'Counter 1', 'is_active' => true]
        );
        CashRegister::firstOrCreate(
            ['code' => 'REG02'],
            ['branch_id' => $hq->id, 'warehouse_id' => $warehouse->id,
                'name' => 'Counter 2', 'is_active' => true]
        );

        // ---- Staff ---------------------------------------------------------
        $owner = User::firstOrCreate(
            ['email' => 'owner@example.test'],
            ['name' => 'Ayesha Rahman', 'password' => Hash::make('password'),
                'status' => 'active', 'branch_id' => $hq->id, 'theme' => 'light', 'email_verified_at' => now()]
        );
        $owner->assignRole('Business Owner');

        $manager = User::firstOrCreate(
            ['email' => 'manager@example.test'],
            ['name' => 'Rahim Uddin', 'password' => Hash::make('password'),
                'status' => 'active', 'branch_id' => $hq->id, 'theme' => 'dark', 'email_verified_at' => now()]
        );
        $manager->assignRole('Manager');

        $cashier = User::firstOrCreate(
            ['email' => 'cashier@example.test'],
            ['name' => 'Karim Hasan', 'password' => Hash::make('password'),
                'status' => 'active', 'branch_id' => $hq->id, 'email_verified_at' => now()]
        );
        $cashier->assignRole('Cashier');

        $accountant = User::firstOrCreate(
            ['email' => 'accounts@example.test'],
            ['name' => 'Nadia Islam', 'password' => Hash::make('password'),
                'status' => 'active', 'branch_id' => $hq->id, 'email_verified_at' => now()]
        );
        $accountant->assignRole('Accountant');

        // An open drawer so the POS shows a live session.
        CashRegisterSession::firstOrCreate(
            ['cash_register_id' => $register->id, 'status' => 'open'],
            ['branch_id' => $hq->id, 'user_id' => $cashier->id, 'opening_balance' => '500.00']
        );

        // ---- Catalog prerequisites -----------------------------------------
        // Units only. Products themselves come from CatalogSeeder, which runs
        // first: the trading history below must be generated from a real
        // assortment, not from a private list of fifteen items that exists
        // nowhere else in the product and is invisible to a shopper.
        Unit::firstOrCreate(['name' => 'Each'], ['short_code' => 'EA', 'decimal_places' => 0, 'is_active' => true]);
        Unit::firstOrCreate(['name' => 'Kilogram'], ['short_code' => 'KG', 'decimal_places' => 3, 'is_active' => true]);

        // Everything saleable that already carries stock at this warehouse.
        // Restricted to the main warehouse so the history below can never try
        // to sell something the inventory service will refuse to deduct.
        $created = Product::query()
            ->where('status', 'active')
            ->whereHas('stocks', fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->inRandomOrder()
            ->take(24)
            ->get();

        if ($created->isEmpty()) {
            $this->command?->warn('No stocked products found — run CatalogSeeder first.');
        }

        // ---- Parties -------------------------------------------------------
        $suppliers = collect([
            ['SUP-001', 'Sunrise Foods Ltd', 'supplier1@example.test'],
            ['SUP-002', 'Delta Distributors', 'supplier2@example.test'],
            ['SUP-003', 'Metro Wholesale Co', 'supplier3@example.test'],
        ])->map(fn ($row) => \App\Models\Supplier::firstOrCreate(['code' => $row[0]], [
            'name' => $row[1], 'company' => $row[1], 'email' => $row[2],
            'phone' => '017' . random_int(10000000, 99999999), 'city' => 'Dhaka', 'is_active' => true,
        ]));

        $customers = collect([
            ['Farida Begum', '01711000001'],
            ['Hasan Ali', '01711000002'],
            ['Nusrat Jahan', '01711000003'],
            ['Tanvir Ahmed', '01711000004'],
            ['Shahina Akter', '01711000005'],
            ['Rafiq Khan', '01711000006'],
        ])->map(fn ($row, $i) => \App\Models\Customer::firstOrCreate(
            ['code' => sprintf('CUS-%04d', $i + 1)],
            ['name' => $row[0], 'phone' => $row[1], 'city' => 'Dhaka',
                'type' => $i === 0 ? 'wholesale' : 'regular', 'is_active' => true]
        ));

        // ---- Opening purchase ---------------------------------------------
        // Recorded through the real service so stock, movements and the payable
        // all exist exactly as they would in production.
        $supplier = $suppliers->first();

        if (\App\Models\Purchase::count() === 0 && $created->count() >= 3) {
            $purchaseService = app(PurchaseService::class);

            // Take the three most expensive lines so the payable is a figure
            // worth looking at rather than pocket change.
            $intake = $created->sortByDesc('cost_price')->take(3)->values();

            $purchaseService->create([
                'supplier_id' => $supplier->id,
                'branch_id' => $hq->id,
                'warehouse_id' => $warehouse->id,
                'user_id' => $owner->id,
                'purchased_at' => now()->subDays(21)->toDateString(),
                'status' => 'received',
                'discount_total' => '0.00',
                'shipping_total' => '25.00',
                'paid_total' => '0.00',
                'notes' => 'Monthly stock intake.',
                'items' => $intake->map(fn ($product) => [
                    'product_id' => $product->id,
                    'quantity' => '40',
                    'unit_cost' => (string) $product->cost_price,
                    'tax_rate_id' => $vat->id,
                    'discount_amount' => '0',
                ])->all(),
            ]);
        }

        // ---- Sales history --------------------------------------------------
        $saleService = app(SaleService::class);
        $cash = PaymentMethod::where('code', 'cash')->first();
        $card = PaymentMethod::where('code', 'card')->first();

        if (\App\Models\Sale::count() === 0) {
            $random = $created;

            for ($day = 20; $day >= 0; $day--) {
                $salesToday = random_int(2, 7);

                for ($n = 0; $n < $salesToday; $n++) {
                    $soldAt = now()->subDays($day)->setTime(random_int(9, 20), random_int(0, 59));

                    $lines = [];
                    foreach (array_rand($random, random_int(2, 4)) as $index) {
                        $product = $random[$index];
                        $quantity = random_int(1, 3);

                        // Never seed a sale the business could not have made: the
                        // inventory service would (correctly) reject it, and the
                        // demo must respect the same rules the UI does.
                        $onHand = (float) $product->stocks()
                            ->where('warehouse_id', $warehouse->id)
                            ->value('quantity');

                        if ($onHand < $quantity + 2) {
                            continue;
                        }

                        $lines[] = [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_sku' => $product->sku,
                            'quantity' => (string) $quantity,
                            'unit_price' => (string) $product->selling_price,
                            'unit_cost' => (string) $product->cost_price,
                            'tax_rate_id' => $product->tax_rate_id,
                            'tax_rate' => (string) $vat->rate,
                            'discount_amount' => '0.00',
                        ];
                    }

                    if ($lines === []) {
                        continue;
                    }

                    $gross = 0.0;
                    foreach ($lines as $line) {
                        $gross += (float) $line['unit_price'] * (float) $line['quantity'];
                    }
                    $total = round($gross * 1.15, 2);

                    $useCard = random_int(1, 100) <= 30;
                    $tendered = $useCard ? $total : (float) ceil($total / 5) * 5;

                    $saleService->create([
                        'branch_id' => $hq->id,
                        'warehouse_id' => $warehouse->id,
                        'cash_register_session_id' => null,
                        'user_id' => $cashier->id,
                        'customer_id' => random_int(1, 100) <= 70 ? $customers->random()->id : null,
                        'items' => $lines,
                        'payments' => [[
                            'payment_method_id' => $useCard ? $card->id : $cash->id,
                            'amount' => number_format($total, 2, '.', ''),
                            'tendered_amount' => number_format($tendered, 2, '.', ''),
                            'reference' => $useCard ? 'AUTH-' . random_int(100000, 999999) : null,
                        ]],
                        'sold_at' => $soldAt,
                    ], 'demo-sale-' . $day . '-' . $n);
                }
            }
        }

        // ---- Expenses -------------------------------------------------------
        foreach ([
            ['Rent', 'RENT', 'Monthly shop rent', '950.00', 28],
            ['Electricity', 'ELEC', 'Utility bill', '180.00', 18],
            ['Transport', 'TRANS', 'Delivery van fuel', '95.00', 9],
            ['Salaries', 'SAL', 'Staff payroll', '1200.00', 5],
            ['Packaging', 'PACK', 'Bags and receipts', '60.00', 2],
        ] as [$categoryName, $code, $description, $amount, $daysAgo]) {
            $category = ExpenseCategory::firstOrCreate(['code' => $code], [
                'name' => $categoryName, 'is_active' => true,
            ]);

            $accountant->forceFill(['last_login_at' => now()])->save();

            auth()->login($accountant);

            try {
                $expense = \App\Models\Expense::firstOrCreate(
                    ['reference' => 'EXP-DEMO-' . $code],
                    [
                        'expense_category_id' => $category->id,
                        'branch_id' => $hq->id,
                        'user_id' => $accountant->id,
                        'payment_method_id' => $cash->id,
                        'amount' => $amount,
                        'tax_amount' => '0.00',
                        'expense_date' => now()->subDays($daysAgo)->toDateString(),
                        'payee' => $categoryName . ' Provider',
                        'description' => $description,
                        'status' => 'approved',
                    ]
                );

                if ($expense->wasRecentlyCreated) {
                    app(\App\Services\AccountingService::class)->postExpenseEntry($expense);
                }
            } finally {
                auth()->logout();
            }
        }

        // ---- Business settings ----------------------------------------------
        foreach ([
            ['app_name', 'Meridian Retail', 'general'],
            ['legal_name', 'Meridian Retail Ltd', 'business'],
            ['registration_number', 'RC-2019-44821', 'business'],
            ['email', 'accounts@meridian.test', 'business'],
            ['phone', '+1 555 0100', 'business'],
            ['website', 'https://meridian.test', 'business'],
            ['address_line', '1 Market Street', 'business'],
            ['city', 'Springfield', 'business'],
            ['state', 'Illinois', 'business'],
            ['country', 'United States', 'business'],
            ['postal_code', '62701', 'business'],
            ['tax_registration_number', 'TX-99881', 'tax'],
            ['invoice_prefix', 'INV-', 'invoice'],
            ['invoice_due_days', '14', 'invoice'],
            ['invoice_terms', 'Goods once sold are not returnable without a receipt. Claims must be raised within 7 days.', 'invoice'],
            ['invoice_footer', 'Thank you for your business.', 'invoice'],
            ['pos_receipt_footer', 'Thank you for shopping with us!', 'pos'],
            ['pos_enable_offline_mode', '1', 'pos'],
            ['pos_auto_print_receipt', '0', 'pos'],
            ['timezone', 'UTC', 'general'],
            ['date_format', 'M j, Y', 'general'],
        ] as [$key, $value, $group]) {
            \App\Models\Setting::set($key, $value, $group, in_array($key, [
                'pos_enable_offline_mode', 'pos_auto_print_receipt', 'invoice_due_days',
            ], true) ? 'integer' : 'string');
        }

        $this->command?->info('Demo business ready.');
        $this->command?->table(
            ['Role', 'Email', 'Password'],
            [
                ['Business Owner', 'owner@example.test', 'password'],
                ['Manager', 'manager@example.test', 'password'],
                ['Cashier', 'cashier@example.test', 'password'],
                ['Accountant', 'accounts@example.test', 'password'],
            ]
        );
    }
}
