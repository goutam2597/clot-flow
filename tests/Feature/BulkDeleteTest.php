<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk delete.
 *
 * The behaviour worth pinning down is the partial one. Deleting forty products
 * where three carry stock has to delete the thirty-seven that are safe and say
 * which three it kept - refusing the whole batch is useless on a catalogue
 * that has been in use for a year, and deleting all forty is how stock rows end
 * up pointing at nothing.
 */
class BulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected ?User $owner = null;

    /**
     * Boot the fixture once per test. The Business helper writes currencies,
     * branches and warehouses that are unique, so calling it per product is a
     * constraint violation rather than a second business.
     */
    protected function owner(): User
    {
        return $this->owner ??= $this->bootBusiness()->owner;
    }

    protected function makeProduct(string $sku): Product
    {
        $this->actingAs($this->owner());

        $this->post('/products', [
            'name' => 'Bulk '.$sku,
            'sku' => $sku,
            'type' => 'simple',
            'status' => 'active',
            'cost_price' => '5',
            'selling_price' => '10',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Product::where('sku', $sku)->firstOrFail();
    }

    public function test_it_deletes_every_selected_row(): void
    {
        $owner = $this->owner();

        $ids = [];
        foreach (['BULK-1', 'BULK-2', 'BULK-3'] as $sku) {
            $ids[] = $this->makeProduct($sku)->id;
        }

        $this->actingAs($owner)
            ->postJson('/bulk/products', ['ids' => $ids])
            ->assertOk()
            ->assertJson(['success' => true, 'deleted' => 3, 'refused' => []]);

        foreach ($ids as $id) {
            $this->assertNull(Product::find($id));
        }
    }

    public function test_it_deletes_the_safe_rows_and_names_the_refused_ones(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::first();

        $safe = $this->makeProduct('BULK-SAFE');
        $blocked = $this->makeProduct('BULK-BLOCKED');

        Stock::create([
            'product_id' => $blocked->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '5',
            'reserved_quantity' => '0',
        ]);

        // 207, not 200 and not 422: the request really was honoured, in part.
        $response = $this->actingAs($owner)
            ->postJson('/bulk/products', ['ids' => [$safe->id, $blocked->id]])
            ->assertStatus(207)
            ->assertJson(['success' => true, 'deleted' => 1]);

        $this->assertCount(1, $response->json('refused'));
        $this->assertSame('Bulk BULK-BLOCKED', $response->json('refused.0.name'));
        $this->assertNotEmpty($response->json('refused.0.reason'));

        $this->assertNull(Product::find($safe->id), 'The safe row should have gone.');
        $this->assertNotNull(Product::find($blocked->id), 'The blocked row must survive.');
    }

    public function test_it_refuses_the_whole_batch_when_nothing_is_safe(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::first();

        $first = $this->makeProduct('BULK-X1');
        $second = $this->makeProduct('BULK-X2');

        foreach ([$first, $second] as $product) {
            Stock::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => '3',
                'reserved_quantity' => '0',
            ]);
        }

        $this->actingAs($owner)
            ->postJson('/bulk/products', ['ids' => [$first->id, $second->id]])
            ->assertStatus(207)
            ->assertJson(['success' => false, 'deleted' => 0]);

        $this->assertNotNull(Product::find($first->id));
        $this->assertNotNull(Product::find($second->id));
    }

    public function test_a_category_holding_products_is_refused(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/categories', ['name' => 'Bulk Parent', 'code' => 'BULKP'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $category = Category::where('code', 'BULKP')->firstOrFail();

        // The product has to actually sit in the category. Creating one
        // elsewhere and expecting the category to be busy would be a test that
        // passes for the wrong reason.
        $this->actingAs($owner)->post('/products', [
            'name' => 'Bulk In Category',
            'sku' => 'BULK-IN-CAT',
            'type' => 'simple',
            'status' => 'active',
            'cost_price' => '5',
            'selling_price' => '10',
            'category_id' => $category->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            $category->id,
            Product::where('sku', 'BULK-IN-CAT')->firstOrFail()->category_id,
            'The fixture product did not land in the category under test.'
        );

        $this->actingAs($owner)
            ->postJson('/bulk/categories', ['ids' => [$category->id]])
            ->assertStatus(207)
            ->assertJson(['success' => false, 'deleted' => 0]);

        $this->assertNotNull(Category::find($category->id));
    }

    public function test_an_empty_selection_is_rejected(): void
    {
        $owner = $this->owner();

        // "Delete nothing" is not a no-op to be silently accepted: it means the
        // client sent a request it did not mean to send.
        $this->actingAs($owner)
            ->postJson('/bulk/products', ['ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_duplicate_ids_are_rejected(): void
    {
        $owner = $this->owner();
        $product = $this->makeProduct('BULK-DUP');

        $this->actingAs($owner)
            ->postJson('/bulk/products', ['ids' => [$product->id, $product->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids.0');

        $this->assertNotNull(Product::find($product->id));
    }

    public function test_an_unknown_resource_is_not_routable(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->postJson('/bulk/nonsense', ['ids' => [1]])
            ->assertNotFound();
    }

    public function test_the_bulk_form_posts_an_array_of_ids(): void
    {
        $owner = $this->owner();

        // A row has to exist: the row checkboxes only render for rows, so on an
        // empty list this would pass for the wrong reason.
        $this->makeProduct('BULK-ROW');

        $html = $this->actingAs($owner)->get('/products')->assertOk()->getContent();

        // The endpoint the client posts to has to exist as a route, and the
        // checkboxes have to name the ids it expects.
        $this->assertStringContainsString('data-bulk-form', $html);
        $this->assertStringContainsString('data-bulk-all', $html);
        $this->assertStringContainsString('data-bulk-row', $html);
        $this->assertStringContainsString(route('bulk.destroy', 'products'), $html);
    }
}
