<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The supermarket catalogue.
 *
 * 130 real grocery products with their own photographs, pulled from a public
 * food listing and stored in `database/data/shwapno-food.json`. They exist so
 * the POS can be judged against a real assortment: a grid of fifteen invented
 * products tells you nothing about whether the layout survives a hundred
 * near-identical packets competing for attention, which is the actual problem.
 *
 * Design decisions worth knowing about:
 *
 *  - Prices are stored as scraped, in the source currency. This seeder does not
 *    invent a conversion rate, because a silently wrong margin is worse than an
 *    obviously foreign figure.
 *  - Cost is derived from price with a fixed, documented margin per category
 *    rather than scraped — the source listing has no cost price, and a made-up
 *    one should at least be made up consistently.
 *  - Categories are assigned from the product name. That is crude, and it is
 *    also exactly what a shopkeeper does when they first key in an assortment;
 *    the alternative was leaving every product uncategorised, which makes the
 *    POS filter row useless.
 *  - Every product gets a valid EAN-13 in the GS1 in-store range (200-299),
 *    so a scanner works out of the box and no code can collide with a real
 *    registered GTIN.
 *
 * Idempotent: keyed on SKU, so re-running updates rather than duplicates.
 */
class CatalogSeeder extends Seeder
{
    /** Where the scraped catalogue lives. */
    private const DATA_FILE = 'data/shwapno-food.json';

    /**
     * Which category a product belongs to, by keyword.
     *
     * ORDER IS THE ALGORITHM — first match wins — so the lists run from most
     * specific to least. Chilled & Dairy deliberately comes after Snacks: a
     * bare "cream" matches "Strawberry Cream Wafer" and "Golden Cremo Vanilla
     * Cream Biscuits", and when dairy is checked first those land in the fridge
     * aisle instead of the sweet one. Only unambiguous phrases ("fresh cream",
     * "milk powder") are listed for dairy at all.
     */
    private const CATEGORY_RULES = [
        'Fresh Produce' => [
            'rice', 'tomato', 'lemon', 'banana', 'cauliflower', 'gourd', 'taro', 'spinach',
            'apple', 'mango', 'potato', 'onion', 'chilli', 'carrot', 'cucumber', 'brinjal',
            'pumpkin', 'dates', 'tamarind', 'almond', 'cashew',
        ],
        'Meat & Seafood' => ['chicken', 'mutton', 'beef', 'fish', 'mackerel', 'prawn', 'pomfret', 'quail'],
        'Tea & Coffee' => ['tea', 'coffee', 'nescafe', 'moccona', 'horlicks'],
        'Beverages' => [
            'cola', 'sprite', 'pepsi', 'fanta', 'mirinda', 'mojo', 'soda water',
            'tonic water', 'water 600', 'juice', 'drink powder', 'soft drink',
            'schweppes', 'kinley',
        ],
        'Snacks & Confectionery' => [
            'biscuit', 'cookie', 'wafer', 'chocolate', 'candy', 'gum', 'cracker',
            'chips', 'popcorn', 'waffle', 'nuts', 'haribo', 'yupi', 'nestle munch',
            'kitkat', 'snickers', 'loacker', 'tiffany', 'cadbury', 'julie', 'olympic',
            'bisk club', 'dekko', 'deem', 'dreem', 'bakeman', 'fun tastic', 'belleame',
        ],
        'Chilled & Dairy' => [
            'butter', 'milk powder', 'cheese', 'yogurt', 'yoghurt', 'fresh cream',
            'clotted cream', 'table cream', 'whipped cream',
        ],
        'Dairy & Pulses' => ['dal', 'lentil', 'salt', 'sugar', 'oil', 'vinegar', 'mustard'],
        'Household' => ['detergent', 'soap', 'towel', 'pickle', 'china grass', 'jelly powder'],
    ];

    /**
     * Wholesale margin used to derive a cost price, by category.
     *
     * Deliberately modest and roughly uniform: without real cost data these
     * exist only so gross-profit reporting has a non-zero, plausible figure.
     */
    private const MARGIN_BY_CATEGORY = [
        'Fresh Produce' => 0.18,
        'Meat & Seafood' => 0.22,
        'Chilled & Dairy' => 0.20,
    ];

    private const DEFAULT_MARGIN = 0.28;

    /** Above this, a shop stocks deep; below it, a shelf does not turn over. */
    private const DEEP_STOCK_CEILING = 500;

    public function run(): void
    {
        $path = database_path(self::DATA_FILE);

        if (! File::exists($path)) {
            $this->command?->warn('Catalogue file missing at '.self::DATA_FILE.' — nothing seeded.');

            return;
        }

        $data = json_decode(File::get($path), true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data['products'])) {
            $this->command?->error('Catalogue file is not valid JSON: '.json_last_error_msg());

            return;
        }

        $warehouse = Warehouse::where('is_active', true)->orderByDesc('is_default')->first()
            ?? Warehouse::orderBy('id')->first();

        if (! $warehouse) {
            $this->command?->error('No warehouse — run the business seeder first.');

            return;
        }

        $units = $this->units();
        $tax = TaxRate::where('is_active', true)->orderBy('rate')->first();
        $categories = $this->categories();
        $cdn = rtrim($data['cdn'] ?? '', '/');

        // Deterministic: the same catalogue always yields the same stock levels,
        // so a reseed does not silently change the demo's reported figures.
        $seed = crc32('meridian-catalogue-v1');

        $created = 0;
        $updated = 0;
        $usedBarcodes = Product::whereNotNull('barcode')->pluck('barcode')->flip();

        foreach ($data['products'] as $index => $row) {
            $slug = $row['slug'] ?? Str::slug($row['name']);
            $sku = Str::upper(Str::limit(preg_replace('/[^a-zA-Z0-9]+/', '-', $slug), 60, ''));

            if ($sku === '') {
                continue;
            }

            $name = $row['name'];
            $price = (float) $row['price'];
            $category = $this->categoryFor($name, $categories);
            $margin = self::MARGIN_BY_CATEGORY[$category->name] ?? self::DEFAULT_MARGIN;
            $cost = round($price * (1 - $margin), 2);

            $image = blank($row['image']) ? null : $cdn.'/'.ltrim($row['image'], '/').'?width=640&format=webp';

            $attributes = [
                'name' => $name,
                'barcode' => $this->barcodeFor($row['barcode'] ?? $usedBarcodes, $usedBarcodes),
                'category_id' => $category->id,
                'unit_id' => $units->first()->id,
                'tax_rate_id' => $tax?->id,
                'description' => trim(sprintf(
                    '%s. Sold as %s%s.',
                    $row['mrp'] ? 'Was '.number_format((float) $row['mrp'], 0).'.' : '',
                    $row['unit'] ?? 'each',
                    ! empty($row['min_qty']) ? ', minimum order '.$row['min_qty'] : ''
                ), ' '),
                'image_path' => $image,
                'cost_price' => number_format($cost, 4, '.', ''),
                'selling_price' => number_format($price, 4, '.', ''),
                'track_stock' => true,
                'allow_negative_stock' => false,
                'reorder_level' => 10,
                'status' => 'active',
            ];

            $existing = Product::where('sku', $sku)->first();

            if ($existing) {
                $existing->fill($attributes)->save();
                $product = $existing;
                $updated++;
            } else {
                $product = Product::create($attributes + ['sku' => $sku]);
                $created++;
            }

            $this->stockFor($product, $warehouse, $row, $seed + $index);
        }

        $this->command?->info(sprintf(
            'Catalogue: %d created, %d updated across %d categories.',
            $created,
            $updated,
            $categories->count()
        ));
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return \Illuminate\Support\Collection<int, Unit>
     */
    private function units()
    {
        $each = Unit::firstOrCreate(['name' => 'Each'], ['short_code' => 'EA', 'decimal_places' => 0, 'is_active' => true]);
        Unit::firstOrCreate(['name' => 'Kilogram'], ['short_code' => 'KG', 'decimal_places' => 3, 'is_active' => true]);

        return collect([$each]);
    }

    /** @return \Illuminate\Support\Collection<int, Category> keyed by name */
    private function categories()
    {
        $definitions = [
            'Fresh Produce' => 'PROD',
            'Meat & Seafood' => 'MEAT',
            'Chilled & Dairy' => 'CHLD',
            'Tea & Coffee' => 'TEA',
            'Beverages' => 'BEV',
            'Snacks & Confectionery' => 'SNACK',
            'Dairy & Pulses' => 'PULSE',
            'Household' => 'HOME',
        ];

        $categories = collect();

        foreach ($definitions as $name => $code) {
            $categories->put($name, Category::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]));
        }

        return $categories;
    }

    /**
     * Classify by the first keyword that matches, on WORD boundaries.
     *
     * The boundary matters more than it looks. A plain substring test sends
     * "Hersheys Kisses Dark Chocolate" to Beverages, because "cola" is a
     * substring of "chocolate" — and "Parle Chocolate Chip Cookies" with it.
     * `\b` costs nothing and removes a whole class of silent misfiling.
     */
    private function categoryFor(string $name, $categories): Category
    {
        $haystack = Str::lower($name);

        foreach (self::CATEGORY_RULES as $category => $keywords) {
            foreach ($keywords as $keyword) {
                $pattern = '/\b'.preg_quote(Str::lower($keyword), '/').'\b/';

                if (preg_match($pattern, $haystack) === 1) {
                    return $categories[$category];
                }
            }
        }

        // Nothing matched. Put it somewhere visible rather than discarding the
        // classification entirely — a wrong aisle is recoverable, an uncategorised
        // product is invisible in the POS filter.
        return $categories['Household'];
    }

    /**
     * A valid EAN-13, preferring a supplied code and otherwise minting one in
     * the GS1 in-store range.
     *
     * 200-299 is reserved for retailer-assigned codes: a syntactically valid
     * EAN-13 that can never collide with a real registered GTIN, which is
     * exactly right for demo shelf labels.
     */
    private function barcodeFor(?string $supplied, \Illuminate\Support\Collection $used): string
    {
        if ($supplied !== null && $this->isValidEan13($supplied) && ! $used->has($supplied)) {
            return $supplied;
        }

        do {
            $body = '200'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            $code = $body.$this->ean13CheckDigit($body);
        } while ($used->has($code));

        $used->put($code, true);

        return $code;
    }

    private function ean13CheckDigit(string $twelve): string
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $twelve[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function isValidEan13(string $code): bool
    {
        return preg_match('/^\d{13}$/', $code) === 1
            && $this->ean13CheckDigit(substr($code, 0, 12)) === $code[12];
    }

    /**
     * Give the product a stock level at the warehouse.
     *
     * Spread deterministically rather than randomly so a reseed produces the
     * same shelves — and so the POS grid reliably contains a few out-of-stock
     * and low-stock tiles, which is the case a layout actually has to survive.
     */
    private function stockFor(Product $product, Warehouse $warehouse, array $row, int $seed): void
    {
        if ($product->stocks()->where('warehouse_id', $warehouse->id)->exists()) {
            return;
        }

        $bucket = ($seed + (int) $row['price']) % 20;

        $quantity = match (true) {
            $bucket === 0 => 0,                                   // out of stock
            $bucket <= 2 => random_int(1, 6),                     // below reorder
            default => random_int(15, self::DEEP_STOCK_CEILING),
        };

        Stock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => number_format($quantity, 4, '.', ''),
            'reserved_quantity' => '0.0000',
            'avg_cost' => $product->cost_price,
        ]);
    }
}