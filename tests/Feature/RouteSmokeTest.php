<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\SaleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Business;
use Tests\TestCase;

/**
 * Renders every GET screen in the product as a signed-in owner.
 *
 * A page that throws is a real defect, not a cosmetic one: it usually means a
 * view references data the controller never passed. This test walks the route
 * table rather than a hand-written list so a newly added screen is covered the
 * moment its route exists.
 */
class RouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->bootBusiness();

        // Give the screens real records so empty states and populated states
        // both get exercised across the suite.
        $this->seedBusinessData();

        $this->actingAs($this->business->owner);
    }

    protected function seedBusinessData(): void
    {
        $product = $this->business->productInStock(400, 8, 12);

        Customer::factory()->count(3)->create();
        Supplier::factory()->count(2)->create();
        ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT', 'is_active' => true]);

        // Completed sales give invoices, journals and stock movements to read.
        //
        // THREE, not one: Eloquent's Builder::hydrate() only applies the
        // prevent-lazy-loading flag when a query returns more than one row, so
        // a single seeded record leaves the guard silently inactive. Screens
        // then render green here and 500 for any real user holding more than
        // one row — which is how the sales list shipped missing three eager
        // loads before this was understood.
        $sales = app(SaleService::class);

        for ($i = 0; $i < 3; $i++) {
            $sales->create([
                'branch_id' => $this->business->branch->id,
                'warehouse_id' => $this->business->warehouse->id,
                'user_id' => $this->business->owner->id,
                'customer_id' => Customer::first()->id,
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
            ], 'smoke-test-sale-'.$i);
        }

        Model::preventLazyLoading(true);
    }

    /**
     * Screens that legitimately require a route parameter we have not built a
     * record for are covered by their own dedicated tests instead.
     */
    protected function skipPatterns(): array
    {
        return [
            'pos/product/*',      // needs a variant-aware payload
        ];
    }

    /**
     * Screens that render the app shell but are reached from somewhere other
     * than the sidebar — the user menu in the topbar — so no nav item should
     * light up. Asserting otherwise would force a fake highlight onto a page
     * the navigation does not own.
     */
    protected function unownedScreens(): array
    {
        return ['profile', 'profile/password'];
    }

    /**
     * The navigation must answer exactly one question: where am I?
     *
     * `sales.*` also matches `sales.returns`, so a Returns screen lit up both
     * "Sales" and "Returns & Refunds" at once. The same shape of bug hides in
     * `inventory.*` vs `inventory.transfers`. Asserting the count — not just
     * the presence — is what catches it, because a screen with two highlights
     * still "has" a highlighted link.
     */
    public function test_exactly_one_sidebar_link_is_active_on_every_screen(): void
    {
        $failures = [];
        $checked = 0;

        foreach ($this->getRoutes() as [$method, $uri]) {
            if ($method !== 'GET' && $method !== 'HEAD') {
                continue;
            }
            if ($this->shouldSkip($uri)) {
                continue;
            }
            if (in_array($uri, $this->unownedScreens(), true)) {
                continue;
            }

            [$path] = $this->resolveUri($uri);
            $response = $this->get($path);

            // Only real screens carry a sidebar. JSON lookups, redirects and
            // guest pages legitimately have no navigation at all.
            if (! in_array($response->getStatusCode(), [200, 302], true)) {
                continue;
            }

            $html = $response->getContent();

            if (! str_contains($html, 'class="sidebar-link')) {
                continue;
            }

            $checked++;

            // Count the highlight itself rather than parsing the anchor: the
            // icon component renders markup between the <a> and its label, and
            // a regex that reaches across it is brittle for no benefit.
            $active = substr_count($html, 'class="sidebar-link active"');

            if ($active !== 1) {
                preg_match_all('/<span class="sidebar-link-label">\s*(.*?)\s*<\/span>/s', $html, $labels);

                $failures[] = sprintf('%s highlights %d nav items', $path, $active);
            }
        }

        $this->assertGreaterThan(20, $checked, 'Not enough screens were checked to be meaningful.');

        $this->assertSame([], $failures, "Navigation is ambiguous on:\n" . implode("\n", $failures));
    }

    public function test_every_get_route_renders_for_a_business_owner(): void
    {
        $failures = [];
        $checked = 0;

        foreach ($this->getRoutes() as [$method, $uri]) {
            if ($method !== 'GET' && $method !== 'HEAD') {
                continue;
            }
            if ($this->shouldSkip($uri)) {
                continue;
            }

            [$path, $parameters] = $this->resolveUri($uri);

            $response = $this->get($path);

            $checked++;

            if ($response->getStatusCode() >= 500) {
                $failures[] = sprintf(
                    '%s %s -> %d: %s',
                    $method,
                    $path,
                    $response->getStatusCode(),
                    $this->firstErrorLine($response->getContent())
                );

                continue;
            }

            // A 200 is not the same as a correct page. A Blade component whose
            // tag was left unclosed by a find-and-replace compiles into a
            // literal "<x-ui.checkbox ...>" in the output: the request succeeds,
            // the status is 200, and the control the page needed is simply
            // absent. That shipped on both the settings and the language
            // screens, and nothing else in the suite could see it.
            if (preg_match('/<x-[a-z]/i', $response->getContent(), $leak)) {
                $failures[] = sprintf(
                    '%s %s rendered an uncompiled component: %s',
                    $method,
                    $path,
                    trim($leak[0])
                );
            }
        }

        $this->assertGreaterThan(40, $checked, 'The route table looks smaller than expected.');

        $this->assertSame([], $failures, "Screens failed to render:\n" . implode("\n", $failures));
    }

    /** @return array<int, array{0: string, 1: string}> */
    protected function getRoutes(): array
    {
        $routes = [];

        foreach (app('router')->getRoutes() as $route) {
            $routes[] = [implode('|', array_diff($route->methods(), ['HEAD'])), $route->uri()];
        }

        return $routes;
    }

    protected function shouldSkip(string $uri): bool
    {
        foreach ($this->skipPatterns() as $pattern) {
            if ($this->matchesPattern($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    protected function matchesPattern(string $pattern, string $uri): bool
    {
        $regex = '#^' . str_replace('\*', '[^/]+', preg_quote($pattern, '#')) . '$#';

        return (bool) preg_match($regex, $uri);
    }

    /**
     * Substitute a real model for every route parameter so the controller can
     * load its record instead of 404-ing.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function resolveUri(string $uri): array
    {
        $parameters = [];

        foreach (explode('/', $uri) as $segment) {
            if (! str_starts_with($segment, '{') || ! str_ends_with($segment, '}')) {
                continue;
            }

            $name = substr($segment, 1, -1);
            $value = $this->valueFor($name);

            if ($value === null) {
                // No record to bind; leave the literal so the route is skipped.
                return [$uri, []];
            }

            $parameters[$name] = $value;
        }

        $path = preg_replace_callback('/\{[^}]+\}/', function (array $m) use (&$parameters) {
            $name = substr($m[0], 1, -1);

            return (string) ($parameters[$name] ?? $m[0]);
        }, $uri);

        return [$path, $parameters];
    }

    protected function valueFor(string $parameter): mixed
    {
        return match ($parameter) {
            'sale' => Sale::first()?->id,
            'journalEntry' => \App\Models\JournalEntry::first()?->id,
            'session' => \App\Models\CashRegisterSession::first()?->id,
            'saleReturn' => \App\Models\SaleReturn::first()?->id,
            'purchase' => \App\Models\Purchase::first()?->id,
            'product' => Product::first()?->id,
            'customer' => Customer::first()?->id,
            'supplier' => Supplier::first()?->id,
            'user' => $this->staff()?->id,
            'language' => \App\Models\Language::first()?->id,
            'role' => \Spatie\Permission\Models\Role::first()?->id,
            'branch' => $this->business->branch->id,
            'cashRegister' => $this->business->register->id,
            'category' => \App\Models\Category::first()?->id,
            'account' => \App\Models\Account::first()?->id,
            'token' => 'test-token',
            default => null,
        };
    }

    protected function staff()
    {
        return $this->business->staffWithRole('Cashier', ['email' => 'cashier@example.test']);
    }

    protected function firstErrorLine(?string $content): string
    {
        if (! $content) {
            return '(empty response)';
        }

        if (preg_match('/<title>(.*?)<\/title>/s', $content, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        return trim(preg_replace('/\s+/', ' ', substr(strip_tags($content), 0, 200)));
    }
}
