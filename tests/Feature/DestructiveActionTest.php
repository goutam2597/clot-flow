<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Destructive row actions.
 *
 * These buttons looked correct and did nothing at all: `x-ui.button` defaults
 * to type="button" so that a button placed beside a form cannot submit it by
 * accident, and six delete buttons relied on the browser's submit default that
 * the component had already overridden. Nothing errored — the form simply never
 * fired.
 *
 * A test that only checks the route exists cannot see that. These drive the
 * routes the way the rendered form does, so a button that stops submitting
 * turns into a failing test rather than a support ticket.
 */
class DestructiveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_created_category_can_be_deleted(): void
    {
        $owner = $this->bootBusiness()->owner;

        $this->actingAs($owner)
            ->post('/categories', ['name' => 'ZZ Probe Category', 'code' => 'ZZPROBE'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $category = Category::where('code', 'ZZPROBE')->firstOrFail();

        $this->actingAs($owner)->delete('/categories/'.$category->id)->assertRedirect();

        // Categories are erased, not archived: nothing references a category
        // once its products have moved, so keeping a tombstone row would only
        // stop the code being reused.
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_product_with_no_references_can_be_deleted(): void
    {
        $owner = $this->bootBusiness()->owner;

        $this->actingAs($owner)
            ->post('/products', $this->productPayload('ZZ-PROBE-1'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'ZZ-PROBE-1')->firstOrFail();

        $this->actingAs($owner)
            ->delete('/products/'.$product->id)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull(Product::find($product->id));
    }

    public function test_a_product_carrying_stock_is_refused(): void
    {
        $owner = $this->bootBusiness()->owner;

        $this->actingAs($owner)
            ->post('/products', $this->productPayload('ZZ-HISTORY-1'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'ZZ-HISTORY-1')->firstOrFail();

        Stock::create([
            'product_id' => $product->id,
            'warehouse_id' => Warehouse::first()->id,
            'quantity' => '5',
            'reserved_quantity' => '0',
        ]);

        // The guard exists so erasing a product cannot leave stock rows and
        // sold lines pointing at nothing. Deactivating it is the alternative
        // the message offers.
        $this->actingAs($owner)
            ->delete('/products/'.$product->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_the_products_index_renders_a_submitting_delete_button_for_every_row(): void
    {
        $owner = $this->bootBusiness()->owner;

        // A row has to exist, or the list renders its empty state and the
        // assertion passes for the wrong reason.
        $this->actingAs($owner)
            ->post('/products', $this->productPayload('ZZ-LISTED-1'))
            ->assertRedirect();

        $html = $this->actingAs($owner)->get('/products')->assertOk()->getContent();

        $this->assertDeleteFormsSubmit($html);
    }

    public function test_the_categories_index_renders_a_submitting_delete_button_for_every_row(): void
    {
        $owner = $this->bootBusiness()->owner;

        $this->actingAs($owner)
            ->post('/categories', ['name' => 'ZZ Listed Category', 'code' => 'ZZLISTED'])
            ->assertRedirect();

        $html = $this->actingAs($owner)->get('/categories')->assertOk()->getContent();

        $this->assertDeleteFormsSubmit($html);
    }

    /* ------------------------------------------------------------------ */

    protected function productPayload(string $sku): array
    {
        return [
            'name' => 'ZZ Probe Product',
            'sku' => $sku,
            'type' => 'simple',
            'status' => 'active',
            'cost_price' => '5',
            'selling_price' => '10',
        ];
    }

    /**
     * Every rendered DELETE form must actually be able to fire.
     *
     * This is the assertion that would have caught all six broken buttons. The
     * route exists, the controller works, the button is on the screen — and the
     * form never submits.
     */
    protected function assertDeleteFormsSubmit(string $html): void
    {
        preg_match_all('/<form\b[\s\S]*?<\/form>/', $html, $matches);

        $forms = array_values(array_filter(
            $matches[0],
            fn (string $form) => str_contains($form, 'name="_method" value="DELETE"')
        ));

        $this->assertNotEmpty($forms, 'No delete forms were rendered.');

        foreach ($forms as $form) {
            $this->assertMatchesRegularExpression(
                '/<button\b[^>]*\btype="submit"/',
                $form,
                'A delete button rendered without type="submit" will not submit its form.'
            );

            $this->assertStringContainsString('name="_token"', $form, 'CSRF token missing.');
        }
    }
}
