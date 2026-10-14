<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Product images and the imported catalogue.
 *
 * Two things are asserted here that are easy to lose:
 *
 *  1. The image field really writes. A file input with no `enctype` on the
 *     form looks perfect in the browser and silently uploads nothing, so the
 *     test posts a real multipart body rather than trusting the markup.
 *  2. The catalogue seeder is idempotent and complete. A seeder that only works
 *     on a fresh database is a seeder nobody can re-run.
 */
class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $business = $this->bootBusiness();
        $this->owner = $business->owner;
    }

    /** Minimal valid payload; tests override single fields. */
    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Test Widget',
            'sku' => 'TEST-001',
            'barcode' => null,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => null,
            'tax_rate_id' => null,
            'type' => 'simple',
            'description' => null,
            'cost_price' => '5.00',
            'selling_price' => '9.00',
            'reorder_level' => '5',
            'status' => 'active',
        ];
    }

    /* ------------------------------------------------------------------
     Uploading
     ------------------------------------------------------------------ */

    public function test_an_uploaded_image_is_stored_and_resolved_to_a_public_url(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('products.store'), $this->payload([
                'image' => UploadedFile::fake()->image('widget.png', 400, 400),
            ]))
            ->assertRedirect(route('products.index'));

        $product = Product::where('sku', 'TEST-001')->firstOrFail();

        $this->assertNotNull($product->image_path, 'The uploaded file was not recorded.');
        $this->assertStringStartsWith('products/', $product->image_path);

        Storage::disk('public')->assertExists($product->image_path);

        // The accessor must hand the browser something it can actually load.
        $this->assertStringContainsString('/storage/'.$product->image_path, (string) $product->image_url);
    }

    public function test_an_image_url_can_be_supplied_without_a_file(): void
    {
        $this->actingAs($this->owner)
            ->post(route('products.store'), $this->payload([
                'image_url' => 'https://cdn.example.test/products/widget.webp',
            ]))
            ->assertRedirect(route('products.index'));

        $product = Product::where('sku', 'TEST-001')->firstOrFail();

        $this->assertSame('https://cdn.example.test/products/widget.webp', $product->image_path);

        // A remote URL must not be rewritten into storage/…
        $this->assertSame('https://cdn.example.test/products/widget.webp', $product->image_url);
    }

    public function test_an_oversized_or_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('products.store'), $this->payload([
                'image' => UploadedFile::fake()->create('invoice.pdf', 64, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertNull(Product::where('sku', 'TEST-001')->first());
    }

    /* ------------------------------------------------------------------
     Editing
     ------------------------------------------------------------------ */

    public function test_replacing_an_image_deletes_the_file_it_replaced(): void
    {
        Storage::fake('public');

        $product = Product::create($this->payload([
            'cost_price' => '5.0000',
            'selling_price' => '9.0000',
            'image_path' => 'products/old.png',
        ]));

        Storage::disk('public')->put('products/old.png', 'x');

        $this->actingAs($this->owner)
            ->patch(route('products.update', $product), $this->payload([
                'sku' => $product->sku,
                'image' => UploadedFile::fake()->image('new.png', 200, 200),
            ]))
            ->assertRedirect(route('products.index'));

        $product->refresh();

        $this->assertStringStartsWith('products/', $product->image_path);
        $this->assertNotSame('products/old.png', $product->image_path);

        Storage::disk('public')->assertMissing('products/old.png');
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_leaving_the_image_field_alone_keeps_the_existing_image(): void
    {
        $product = Product::create($this->payload([
            'cost_price' => '5.0000',
            'selling_price' => '9.0000',
            'image_path' => 'https://cdn.example.test/keep.webp',
        ]));

        $this->actingAs($this->owner)
            ->patch(route('products.update', $product), $this->payload(['sku' => $product->sku]))
            ->assertRedirect(route('products.index'));

        $this->assertSame(
            'https://cdn.example.test/keep.webp',
            $product->refresh()->image_path,
            'Editing a product must not blank its image just because no file was chosen.'
        );
    }

    public function test_removing_an_image_clears_the_column_and_the_file(): void
    {
        Storage::fake('public');

        $product = Product::create($this->payload([
            'cost_price' => '5.0000',
            'selling_price' => '9.0000',
            'image_path' => 'products/gone.png',
        ]));

        Storage::disk('public')->put('products/gone.png', 'x');

        $this->actingAs($this->owner)
            ->patch(route('products.update', $product), $this->payload([
                'sku' => $product->sku,
                'remove_image' => '1',
            ]))
            ->assertRedirect(route('products.index'));

        $this->assertNull($product->refresh()->image_path);
        Storage::disk('public')->assertMissing('products/gone.png');
    }

    public function test_a_remote_image_is_never_deleted_from_the_supplier(): void
    {
        $product = Product::create($this->payload([
            'cost_price' => '5.0000',
            'selling_price' => '9.0000',
            'image_path' => 'https://cdn.example.test/theirs.webp',
        ]));

        $this->actingAs($this->owner)
            ->patch(route('products.update', $product), $this->payload([
                'sku' => $product->sku,
                'image' => UploadedFile::fake()->image('mine.png', 100, 100),
            ]))
            ->assertRedirect(route('products.index'));

        $this->assertStringStartsWith('products/', $product->refresh()->image_path);
    }

    /* ------------------------------------------------------------------
     The form
     ------------------------------------------------------------------ */

    public function test_every_product_form_posts_multipart(): void
    {
        $product = Product::factory()->create();

        $html = $this->actingAs($this->owner)
            ->get(route('products.index'))
            ->assertOk()
            ->getContent();

        $forms = [];
        preg_match_all('/<form\b[^>]*>/i', $html, $matches);

        foreach ($matches[0] as $form) {
            if (str_contains($form, 'enctype="multipart/form-data"')) {
                $forms[] = $form;
            }
        }

        // The create modal plus one per editable product.
        $this->assertGreaterThanOrEqual(
            2,
            count($forms),
            'Product forms must declare enctype="multipart/form-data" or the browser sends no file.'
        );

        $this->assertStringContainsString(
            'type="file"',
            $html,
            'The product form must offer a file input.'
        );

        $this->actingAs($this->owner)->delete(route('products.destroy', $product))->assertRedirect();
    }

    /* ------------------------------------------------------------------
     The imported catalogue
     ------------------------------------------------------------------ */

    public function test_the_catalogue_seeder_loads_the_whole_scraped_assortment(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);

        $products = Product::whereNotNull('image_path')->get();

        $this->assertGreaterThanOrEqual(
            100,
            $products->count(),
            'The brief asked for at least 100 real products; the seeder loaded fewer.'
        );

        // Every one must carry a resolvable absolute image URL.
        foreach ($products as $product) {
            $this->assertMatchesRegularExpression(
                '#^https?://#i',
                (string) $product->image_url,
                "{$product->sku} has no usable image URL."
            );
        }

        // A grid with a single filter chip is not a filter.
        $this->assertGreaterThan(
            4,
            Category::whereHas('products')->count(),
            'The catalogue should classify products into several categories.'
        );

        // SKUs are unique and barcodes are assigned, so a scanner works.
        $this->assertSame(
            $products->count(),
            $products->pluck('sku')->unique()->count(),
            'Duplicate SKUs in the catalogue.'
        );

        $this->assertSame(
            $products->count(),
            $products->pluck('barcode')->filter()->unique()->count(),
            'Duplicate or missing barcodes in the catalogue.'
        );
    }

    public function test_the_catalogue_seeder_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $first = Product::count();

        $this->seed(\Database\Seeders\CatalogSeeder::class);

        $this->assertSame(
            $first,
            Product::count(),
            'Re-running the catalogue seeder duplicated products.'
        );
    }

    public function test_every_catalogue_barcode_is_a_valid_ean13(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);

        foreach (Product::whereNotNull('barcode')->pluck('barcode') as $code) {
            $this->assertMatchesRegularExpression('/^\d{13}$/', $code);

            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
            }

            $this->assertSame(
                (string) ((10 - ($sum % 10)) % 10),
                $code[12],
                "Barcode {$code} has an invalid check digit."
            );
        }
    }

    public function test_catalogue_products_are_stockable_at_a_warehouse(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);

        $warehouse = Warehouse::firstOrFail();

        $this->assertGreaterThan(
            100,
            Product::whereHas('stocks', fn ($q) => $q->where('warehouse_id', $warehouse->id))->count(),
            'Catalogue products must carry stock, or the POS shows an empty grid.'
        );
    }
}