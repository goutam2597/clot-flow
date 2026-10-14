<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Business;
use Tests\TestCase;

/**
 * Authentication and authorization.
 *
 * Permissions are enforced on the server: hiding a button is never treated as
 * protection, so each restricted screen is exercised without the permission
 * and asserted to be refused.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->bootBusiness();
    }

    public function test_the_root_path_sends_guests_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_login_screen_renders_for_guests(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in', false);
    }

    public function test_a_valid_user_can_sign_in(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-horse',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        foreach (range(1, 6) as $ignored) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_inactive_account_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create(['password' => Hash::make('correct-horse')]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_suspended_account_already_signed_in_is_locked_out(): void
    {
        $user = $this->business->staffWithRole('Cashier', ['email' => 'blocked@example.test']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->update(['status' => 'suspended']);

        $this->actingAs($user->refresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_can_sign_out(): void
    {
        $this->actingAs($this->business->owner)
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_guests_are_sent_to_the_login_screen(): void
    {
        foreach (['/dashboard', '/pos', '/sales', '/products', '/accounting', '/admin/users'] as $path) {
            $this->get($path)->assertRedirect(route('login'), "{$path} must not be public.");
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function restrictedScreens(): array
    {
        return [
            'point of sale' => ['/pos', 'pos.access'],
            'sales register' => ['/sales', 'sales.view'],
            'product catalog' => ['/products', 'products.view'],
            'inventory' => ['/inventory', 'inventory.view'],
            'customers' => ['/customers', 'customers.view'],
            'suppliers' => ['/suppliers', 'suppliers.view'],
            'purchases' => ['/purchases', 'purchases.view'],
            'expenses' => ['/expenses', 'expenses.view'],
            'invoices' => ['/invoices', 'invoices.view'],
            'accounting' => ['/accounting', 'accounting.view'],
            'reports' => ['/reports', 'reports.view'],
            'user administration' => ['/admin/users', 'settings.manage'],
            'roles' => ['/admin/roles', 'settings.manage'],
            'languages' => ['/admin/languages', 'settings.manage'],
            'settings' => ['/admin/settings', 'settings.manage'],
            'audit log' => ['/admin/audit-logs', 'settings.manage'],
            'branches' => ['/branches', 'branches.manage'],
        ];
    }

    /**
     * @dataProvider restrictedScreens
     */
    public function test_a_user_without_the_permission_is_refused(string $path): void
    {
        // A plain employee with no roles at all.
        $nobody = User::factory()->create(['branch_id' => $this->business->branch->id]);

        $this->actingAs($nobody)
            ->get($path)
            ->assertForbidden("{$path} must check its permission on the server.");
    }

    /**
     * @dataProvider restrictedScreens
     */
    public function test_the_owner_can_reach_every_screen(string $path): void
    {
        $this->actingAs($this->business->owner)->get($path)->assertOk();
    }

    public function test_a_cashier_cannot_void_a_sale(): void
    {
        $cashier = $this->business->staffWithRole('Cashier', ['email' => 'cashier-void@example.test']);
        $sale = $this->makeSale();

        $this->actingAs($cashier)
            ->post(route('sales.void', $sale), ['reason' => 'Nope'])
            ->assertForbidden();
    }

    public function test_a_cashier_cannot_see_the_accounting_ledger(): void
    {
        $cashier = $this->business->staffWithRole('Cashier', ['email' => 'cashier-ledger@example.test']);

        $this->actingAs($cashier)->get(route('accounting.journals'))->assertForbidden();
    }

    public function test_a_user_cannot_change_their_own_account_status(): void
    {
        $this->actingAs($this->business->owner)
            ->post(route('admin.users.status', $this->business->owner), ['status' => 'suspended'])
            ->assertSessionHas('error');

        $this->assertSame('active', $this->business->owner->refresh()->status);
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $this->actingAs($this->business->owner)
            ->delete(route('admin.users.destroy', $this->business->owner))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->business->owner->id]);
    }

    public function test_a_branch_scoped_user_cannot_read_another_branch_sale(): void
    {
        $otherBranch = \App\Models\Branch::factory()->create();
        $sale = $this->makeSale();

        $scoped = $this->business->staffWithRole('Cashier', [
            'email' => 'scoped@example.test',
            'branch_id' => $otherBranch->id,
        ]);

        // 404 rather than 403: the branch scope means the record must not even
        // be revealed to exist, which leaks less than "forbidden" does.
        $this->actingAs($scoped)->get(route('sales.show', $sale))->assertNotFound();
        $this->actingAs($scoped)->get(route('sales.invoice', $sale))->assertNotFound();
    }

    public function test_a_branch_scoped_user_does_not_see_other_branches_records_in_lists(): void
    {
        $otherBranch = \App\Models\Branch::factory()->create();
        $this->makeSale();

        $scoped = $this->business->staffWithRole('Cashier', [
            'email' => 'scoped-list@example.test',
            'branch_id' => $otherBranch->id,
        ]);

        $this->actingAs($scoped)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertDontSee($this->sale->invoice_number, false);
    }

    public function test_head_office_staff_with_branch_management_see_every_branch(): void
    {
        $otherBranch = \App\Models\Branch::factory()->create();
        $sale = $this->makeSale();

        $headOffice = User::factory()->create([
            'branch_id' => $otherBranch->id,
        ]);
        $headOffice->assignRole(\Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Administrator', 'guard_name' => 'web',
        ]));

        $this->actingAs($headOffice)
            ->get(route('sales.show', $sale))
            ->assertOk();
    }

    protected ?\App\Models\Sale $sale = null;

    protected function makeSale()
    {
        $product = $this->business->productInStock(20, 5, 9);

        return $this->sale = app(\App\Services\SaleService::class)->create([
            'branch_id' => $this->business->branch->id,
            'warehouse_id' => $this->business->warehouse->id,
            'user_id' => $this->business->owner->id,
            'items' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity' => '1',
                'unit_price' => '9.0000',
                'unit_cost' => '5.0000',
                'tax_rate' => '0',
            ]],
            'payments' => [['payment_method_id' => $this->business->cashMethod->id, 'amount' => '9.00']],
        ], 'authz-sale-' . uniqid());
    }
}
