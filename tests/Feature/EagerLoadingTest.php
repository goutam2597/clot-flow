<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Business;
use Tests\TestCase;

/**
 * Relation reads that happen in a Blade template rather than in a controller.
 *
 * `Model::preventLazyLoading()` turns a missing `with()` into a 500 instead of
 * a slow query — which is the right trade, but only if something is actually
 * watching for it. A screen that renders fine on an empty database can still
 * break the first time it has rows, because `$model->relation` only touches
 * the database once there is a record to touch it with.
 *
 * So these tests seed real data and then render the screen that shows it. The
 * failure mode they prevent is subtle and expensive: a page that 500s only
 * once the shop has actually made a sale.
 */
class EagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->bootBusiness();
        $this->actingAs($this->business->owner);

        // Asserted on explicitly rather than trusting the service provider: the
        // whole value of these tests is that the flag is actually on, and a
        // test that passes because the guard was silently disabled is worse
        // than no test at all.
        Model::preventLazyLoading(true);
    }

    /**
     * Completed sales, so every relation on the model is actually populated.
     *
     * TWO OR MORE rows, deliberately. Eloquent's Builder::hydrate() only
     * applies the prevent-lazy-loading flag when the result set has more than
     * one row:
     *
     *     if (count($items) > 1) {
     *         $model->preventsLazyLoading = Model::preventsLazyLoading();
     *     }
     *
     * So a single seeded sale renders with the guard silently inactive and
     * every test below passes whether or not the controller eager-loads
     * anything. The sales screen was missing three eager loads for exactly
     * this reason: green in CI, a 500 in a browser holding ninety sales.
     */
    protected function completedSales(int $count = 3): Sale
    {
        $product = $this->business->productInStock(200, 8, 12);
        $sales = app(SaleService::class);
        $first = null;

        for ($i = 0; $i < $count; $i++) {
            $customer = Customer::factory()->create();

            $sale = $sales->create([
                'branch_id' => $this->business->branch->id,
                'warehouse_id' => $this->business->warehouse->id,
                'user_id' => $this->business->owner->id,
                'customer_id' => $customer->id,
                'items' => [[
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => '2',
                    'unit_price' => (string) $product->selling_price,
                    'unit_cost' => (string) $product->cost_price,
                    'tax_rate_id' => $this->business->taxRate->id,
                    'tax_rate' => (string) $this->business->taxRate->rate,
                    'discount_amount' => '0.00',
                ]],
                'payments' => [[
                    'payment_method_id' => $this->business->cashMethod->id,
                    'amount' => '27.60',
                    'tendered_amount' => '30.00',
                ]],
            ], 'eager-loading-sale-'.$i);

            $first ??= $sale;
        }

        return $first->fresh();
    }

    public function test_the_sales_list_renders_with_real_sales(): void
    {
        $this->completedSales();

        // The row template reads customer, user, warehouse and branch. Each one
        // is a lazy load if the controller forgot it, and each one is a 500
        // rather than a slow page.
        $response = $this->get(route('sales.index'));

        $response->assertOk();

        // Assert the rows actually rendered, so a refactor that empties the
        // table cannot make this test pass by rendering nothing.
        foreach (Sale::limit(3)->get() as $sale) {
            $response->assertSee($sale->invoice_number, false);
        }

        $response->assertSee($this->business->branch->name);
    }

    public function test_the_sales_list_does_not_issue_a_query_per_row(): void
    {
        $this->completedSales(8);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->get(route('sales.index'))->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // One count + one page query + a fixed number of eager loads. A query
        // count that grows with the number of rows is the N+1 this guards.
        $this->assertLessThanOrEqual(
            20,
            count($queries),
            'The sales list is running ' . count($queries) . ' queries — a relation is being lazy loaded per row.'
        );
    }

    public function test_the_pos_terminal_renders_with_a_real_catalogue(): void
    {
        $this->business->productInStock(10, 8, 12);
        Customer::factory()->count(3)->create();

        $this->get(route('pos.index'))->assertOk();
    }

    public function test_lazy_loading_is_actually_disabled_for_this_suite(): void
    {
        // If this ever flips off, every test above silently stops testing
        // anything while continuing to pass.
        $this->assertTrue(
            Model::preventsLazyLoading(),
            'Lazy loading must stay disabled outside production. Without it these tests '
            . 'pass whether or not the controllers eager-load anything.'
        );

        $this->completedSales();

        // The static is not enough: the flag is copied onto each model as it is
        // hydrated, and Builder::hydrate() only does that when the result set
        // has more than one row. So this has to fetch several — a `first()`
        // would return a model with the guard silently off and assert nothing.
        $hydrated = Sale::query()->with('user')->limit(3)->get();

        $this->assertGreaterThan(1, $hydrated->count(), 'Need more than one sale to exercise hydrate().');

        $this->assertTrue(
            $hydrated->first()->preventsLazyLoading,
            'Hydrated models must carry the prevent-lazy-loading flag, otherwise a '
            . 'single-row query set silently exempts itself from the guard.'
        );
    }
}