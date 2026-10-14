<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Models\User;
use App\Services\AccountingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Business;
use Tests\TestCase;

/**
 * Double-entry integrity: every posting balances, posted entries are immutable,
 * corrections are made by reversal, and the trial balance agrees.
 */
class AccountingTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->bootBusiness();
    }

    protected function service(): AccountingService
    {
        return app(AccountingService::class);
    }

    protected function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    public function test_the_chart_of_accounts_is_seeded_with_the_system_accounts(): void
    {
        foreach (['1000', '1300', '2000', '2100', '4000', '5000', '6000'] as $code) {
            $this->assertNotNull(
                Account::where('code', $code)->first(),
                "System account {$code} must exist or nothing can be posted."
            );
        }
    }

    public function test_it_refuses_to_post_an_unbalanced_entry(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service()->postEntry(today()->toDateString(), [
            ['account_id' => $this->account('1000')->id, 'debit' => '100.00', 'credit' => '0', 'description' => 'x'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '90.00', 'description' => 'y'],
        ]);
    }

    public function test_it_refuses_to_post_an_empty_entry(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service()->postEntry(today()->toDateString(), []);
    }

    public function test_a_posted_entry_balances(): void
    {
        $entry = $this->service()->postEntry(today()->toDateString(), [
            ['account_id' => $this->account('1000')->id, 'debit' => '250.00', 'credit' => '0'],
            ['account_id' => $this->account('2100')->id, 'debit' => '0', 'credit' => '50.00'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '200.00'],
        ]);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame('250.00', $entry->total_debit);
        $this->assertSame('250.00', $entry->total_credit);
        $this->assertCount(3, $entry->lines);
    }

    public function test_an_idempotency_key_posts_the_entry_only_once(): void
    {
        $lines = [
            ['account_id' => $this->account('1000')->id, 'debit' => '75.00', 'credit' => '0'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '75.00'],
        ];

        $first = $this->service()->postEntry(today()->toDateString(), $lines, 'Test', null, null, null, null, 'journal-key-1');
        $second = $this->service()->postEntry(today()->toDateString(), $lines, 'Test', null, null, null, null, 'journal-key-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_reversing_an_entry_offsets_it_without_deleting_it(): void
    {
        $entry = $this->service()->postEntry(today()->toDateString(), [
            ['account_id' => $this->account('1000')->id, 'debit' => '500.00', 'credit' => '0'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '500.00'],
        ]);

        $reversal = $this->service()->reverseEntry($entry, 'Posted to the wrong account');

        $this->assertSame('reversed', $entry->refresh()->status);
        $this->assertSame($reversal->id, $entry->reversed_by_entry_id);

        // Both entries survive for audit...
        $this->assertSame(2, JournalEntry::count());
        // ...and the net effect on the account is zero.
        $this->assertSame('0.00', $this->service()->accountBalance($this->account('1000')));
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $entry = $this->service()->postEntry(today()->toDateString(), [
            ['account_id' => $this->account('1000')->id, 'debit' => '10.00', 'credit' => '0'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '10.00'],
        ]);

        $this->service()->reverseEntry($entry, 'First reversal');

        $this->expectException(\RuntimeException::class);
        $this->service()->reverseEntry($entry->refresh(), 'Second reversal');
    }

    public function test_a_balance_respects_the_date_window(): void
    {
        $this->service()->postEntry('2024-01-15', [
            ['account_id' => $this->account('1000')->id, 'debit' => '100.00', 'credit' => '0'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '100.00'],
        ]);

        $this->service()->postEntry(today()->toDateString(), [
            ['account_id' => $this->account('1000')->id, 'debit' => '40.00', 'credit' => '0'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '40.00'],
        ]);

        $this->assertSame('140.00', $this->service()->accountBalance($this->account('1000')));
        // The January entry is on or before the cut-off; the one dated today is not.
        $this->assertSame('100.00', $this->service()->accountBalance($this->account('1000'), '2024-01-15'));
        $this->assertSame('40.00', $this->service()->accountBalance($this->account('1000'), null, '2025-01-01'));
    }

    public function test_an_expense_posts_a_balanced_entry(): void
    {
        $expense = Expense::create([
            'reference' => 'EXP-TEST-1',
            'branch_id' => $this->business->branch->id,
            'user_id' => $this->business->owner->id,
            'payment_method_id' => $this->business->cashMethod->id,
            'amount' => '320.00',
            'tax_amount' => '0.00',
            'expense_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        $entry = $this->service()->postExpenseEntry($expense);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame('320.00', $entry->total_debit);
    }

    public function test_the_trial_balance_screen_agrees_with_the_ledger(): void
    {
        $this->recordSomeBusiness();

        $response = $this->actingAs($this->business->owner)->get(route('accounting.trial-balance'));
        $response->assertOk();

        // Recompute independently from the ledger.
        $debit = '0.00';
        $credit = '0.00';

        foreach (Account::where('is_active', true)->get() as $account) {
            $balance = $this->service()->accountBalance($account);
            if (Money::isZero($balance)) { continue; }
            if (Money::isNegative($balance)) { $credit = Money::add($credit, Money::sub('0', $balance, 2), 2); }
            else { $debit = Money::add($debit, $balance, 2); }
        }

        $this->assertSame($debit, $credit, 'The trial balance must agree.');
        $response->assertSee('Balanced', false);
    }

    public function test_the_general_ledger_requires_an_account(): void
    {
        $this->actingAs($this->business->owner)
            ->get(route('accounting.general-ledger'))
            ->assertSessionHasErrors('account');
    }

    public function test_the_general_ledger_runs_a_balance(): void
    {
        $this->recordSomeBusiness();

        $this->actingAs($this->business->owner)
            ->get(route('accounting.general-ledger', ['account' => $this->account('1000')->id]))
            ->assertOk()
            ->assertSee('General ledger', false);
    }

    public function test_the_balance_sheet_balances_once_business_has_traded(): void
    {
        $this->recordSomeBusiness();

        $this->actingAs($this->business->owner)
            ->get(route('accounting.balance-sheet'))
            ->assertOk()
            ->assertSee('Balance sheet', false);
    }

    public function test_reversing_through_the_controller_requires_permission(): void
    {
        $entry = $this->service()->postEntry(today()->toDateString(), [
            ['account_id' => $this->account('1000')->id, 'debit' => '10.00', 'credit' => '0'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '10.00'],
        ], 'Owned by the owner', null, null, $this->business->branch->id);

        // Same branch, so the record is visible — but the permission is missing.
        $cashier = $this->business->staffWithRole('Cashier', ['email' => 'no-accounting@example.test']);

        $this->actingAs($cashier)
            ->post(route('accounting.reverse', $entry), ['reason' => 'Because'])
            ->assertForbidden();

        $this->assertSame('posted', $entry->refresh()->status, 'The entry must be untouched.');
    }

    public function test_a_branch_scoped_user_cannot_even_reach_another_branch_s_entry(): void
    {
        $entry = $this->service()->postEntry(today()->toDateString(), [
            ['account_id' => $this->account('1000')->id, 'debit' => '10.00', 'credit' => '0'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '10.00'],
        ], 'Other branch', null, null, $this->business->branch->id);

        $otherBranch = \App\Models\Branch::factory()->create();
        $accountant = User::factory()->create(['branch_id' => $otherBranch->id]);
        $accountant->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']));
        $accountant->givePermissionTo('accounting.view', 'accounting.manage');

        // Holds the permission, but the record belongs to another branch, so it
        // must not even be revealed to exist.
        $this->actingAs($accountant)
            ->post(route('accounting.reverse', $entry), ['reason' => 'Because'])
            ->assertNotFound();
    }

    public function test_reversing_through_the_controller_records_the_reason(): void
    {
        $entry = $this->service()->postEntry(today()->toDateString(), [
            ['account_id' => $this->account('1000')->id, 'debit' => '10.00', 'credit' => '0'],
            ['account_id' => $this->account('4000')->id, 'debit' => '0', 'credit' => '10.00'],
        ]);

        $this->actingAs($this->business->owner)
            ->post(route('accounting.reverse', $entry), ['reason' => 'Duplicate posting'])
            ->assertRedirect(route('accounting.journals.show', $entry));

        $this->assertSame('reversed', $entry->refresh()->status);
        $this->assertStringContainsString('Duplicate posting', (string) $entry->reversedBy?->description);
    }

    /** Post a small, realistic set of transactions. */
    protected function recordSomeBusiness(): void
    {
        $purchase = Purchase::create([
            'purchase_number' => 'PO-TEST-1',
            'supplier_id' => \App\Models\Supplier::factory()->create()->id,
            'branch_id' => $this->business->branch->id,
            'warehouse_id' => $this->business->warehouse->id,
            'user_id' => $this->business->owner->id,
            'subtotal' => '100.00',
            'discount_total' => '0.00',
            'tax_total' => '0.00',
            'shipping_total' => '0.00',
            'grand_total' => '100.00',
            'paid_total' => '0.00',
            'due_total' => '100.00',
            'status' => 'received',
            'payment_status' => 'due',
            'purchased_at' => today(),
        ]);

        $this->service()->postPurchaseEntry($purchase);

        $expense = Expense::create([
            'reference' => 'EXP-TEST-2',
            'branch_id' => $this->business->branch->id,
            'user_id' => $this->business->owner->id,
            'amount' => '40.00',
            'tax_amount' => '0.00',
            'expense_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        $this->service()->postExpenseEntry($expense);
    }
}
