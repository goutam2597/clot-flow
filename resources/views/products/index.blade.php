<x-app-layout>
    <x-slot:title>{{ __('Products') }} Â· {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Products')" :subtitle="__('The master catalog behind the POS, purchases and inventory.')">
        <x-slot:actions>
            @can('products.manage')
                <x-ui.button icon="plus" data-modal-open="#create-product">{{ __('New Product') }}</x-ui.button>
                <x-ui.button :href="route('categories.index')" variant="outline" icon="tag">{{ __('Categories') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- `.stat-grid` forces one shared row height; every card carries a hint so
         the values also sit on a common baseline. --}}
    <div class="stat-grid">
        <x-ui.stat-card icon="box" :label="__('Products')"
            :value="\App\Support\Formatter::number($stats['total'], 0)"
            :hint="__('Across all categories')" />

        <x-ui.stat-card icon="check-circle" :label="__('Active')"
            :value="\App\Support\Formatter::number($stats['active'], 0)"
            :hint="__('Sellable on the terminal')" />

        <x-ui.stat-card icon="alert" :label="__('Low stock')"
            :value="\App\Support\Formatter::number($stats['low_stock'], 0)"
            :hint="__('At or below reorder level')" />

        <x-ui.stat-card icon="money" :label="__('Stock value')"
            :value="\App\Support\Formatter::money($stats['stock_value'])"
            :hint="__('At cost price')" />
    </div>

    {{-- One filter bar, not a card with a heading: the controls are the
         content, and a heading above them only adds a row to scan past. --}}
    <form method="GET" action="{{ route('products.index') }}">
        <div class="card mb-4">
            <div class="card-body">
                <div class="field-row is-tight">
                    <x-ui.input wrapper-class="field-wide" name="q" :label="__('Search')"
                        :value="$filters['q']" :placeholder="__('Name, SKU or barcode')" />

                    <x-ui.select name="category_id" :label="__('Category')"
                        :options="$categories" :value="$filters['category_id']"
                        :placeholder="__('All categories')" />

                    <x-ui.select name="brand_id" :label="__('Brand')"
                        :options="$brands" :value="$filters['brand_id']"
                        :placeholder="__('All brands')" />

                    <x-ui.select name="status" :label="__('Status')"
                        :options="['active' => __('Active'), 'inactive' => __('Inactive')]"
                        :value="$filters['status']" :placeholder="__('Any status')" />

                    <x-ui.select name="stock" :label="__('Stock')"
                        :options="['low' => __('Low stock'), 'out' => __('Out of stock')]"
                        :value="$filters['stock']" :placeholder="__('Any stock')" />
                </div>

                <div class="field-actions">
                    <x-ui.button type="submit" icon="search">{{ __('Apply') }}</x-ui.button>

                    @if ($filters['q'] !== '' || $filters['category_id'] || $filters['brand_id'] || $filters['status'] || $filters['stock'])
                        <x-ui.button variant="ghost" :href="route('products.index')">
                            {{ __('Clear all filters') }}
                        </x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    </form>

    {{-- data-bulk-scope ties the table's checkboxes to the bar above them. --}}
    <div data-bulk-scope>
    @can('products.manage')
        <x-ui.bulk-form :action="route('bulk.destroy', 'products')" :subject="__('products')" />
    @endcan

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="cell-check">
                            <x-ui.checkbox variant="row" data-bulk-all
                                           :label="__('Select every product on this page')" />
                        </th>
                        <th>{{ __('Product') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th class="num">{{ __('Cost') }}</th>
                        <th class="num">{{ __('Price') }}</th>
                        <th class="num">{{ __('Stock') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="cell-actions"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        @php
                            $available = $product->stock_quantity !== null
                                ? bcsub((string) $product->stock_quantity, (string) ($product->reserved_quantity ?? 0), 2)
                                : null;
                            $isLow = $product->track_stock && $available !== null
                                && (float) $available > 0 && (float) $available <= (float) $product->reorder_level;
                            $isOut = $product->track_stock && $available !== null && (float) $available <= 0;
                        @endphp
                        <tr>
                            <td class="cell-check">
                                <x-ui.checkbox variant="row" data-bulk-row
                                               value="{{ $product->id }}"
                                               :label="__('Select :name', ['name' => $product->name])" />
                            </td>
                            {{-- Same identity block as the till's product card, so
                                 a product looks like itself in both places. --}}
                            <td>
                                <div class="row-ident">
                                    @if ($product->image_url)
                                        <img class="row-thumb" src="{{ $product->image_url }}"
                                             alt="" loading="lazy" width="32" height="32">
                                    @else
                                        <span class="row-thumb" aria-hidden="true">
                                            <x-ui.icon name="box" :size="15" />
                                        </span>
                                    @endif

                                    <div style="min-width: 0;">
                                        <div class="row-ident-name">{{ $product->name }}</div>
                                        <div class="row-ident-meta">{{ $product->sku ?: 'â€”' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $product->category?->name ?? 'â€”' }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::money($product->cost_price) }}</td>
                            <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($product->selling_price) }}</td>
                            <td class="num">
                                @if (! $product->track_stock)
                                    <span class="fs-sm text-muted">â€”</span>
                                @elseif ($isOut)
                                    <x-ui.badge type="danger">{{ __('Out') }}</x-ui.badge>
                                @elseif ($isLow)
                                    <x-ui.badge type="warning">{{ \App\Support\Formatter::number($available, 0) }}</x-ui.badge>
                                @else
                                    <span class="financial">{{ \App\Support\Formatter::number($available, 0) }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($product->status === 'active')
                                    <x-ui.badge type="success">{{ __('Active') }}</x-ui.badge>
                                @else
                                    <x-ui.badge type="default">{{ __('Inactive') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="cell-actions">
                                @can('products.manage')
                                    <div class="flex-center gap-2" style="justify-content: flex-end;">
                                        <x-ui.button variant="ghost" size="sm" icon="edit" data-modal-open="#edit-product-{{ $product->id }}" :aria-label="__('Edit :name', ['name' => $product->name])" />
                                        <x-ui.delete-form :action="route('products.destroy', $product)"
                                                                     :label="__('Delete :name', ['name' => $product->name])"
                                                                     :confirm="__('Delete this product?')" />
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty-state icon="box" :title="__('No products found')" :text="__('Create your first product to start selling.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {!! $products->links('partials.pagination') !!}
    @can('products.manage')
        <x-ui.modal id="create-product" :title="__('New Product')">
            <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" data-prevent-double>
                @csrf
                @include('products.form', ['product' => null, 'useOld' => true])
                <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                    <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" icon="check">{{ __('Create Product') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        @foreach ($products as $product)
            <x-ui.modal id="edit-product-{{ $product->id }}" :title="__('Edit :name', ['name' => $product->name])">
                {{-- enctype is required for the image upload; the browser silently refuses
                         to send the file without it. `@method` still works â€”
                         Symfony reads $_FILES from the real POST regardless of
                         the spoofed verb. --}}
                <form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data" data-prevent-double>
                    @csrf
                    @method('PATCH')
                    @include('products.form', ['product' => $product, 'useOld' => false])
                    <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                        <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                        <x-ui.button type="submit" icon="check">{{ __('Save Changes') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endforeach
    @endcan
    </div>

</x-app-layout>
