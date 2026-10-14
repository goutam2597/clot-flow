<x-app-layout>
    <x-slot:title>{{ __('Categories') }} — {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Categories')" :subtitle="__('Organise the catalog so the POS stays fast to search.')">
        <x-slot:actions>
            <x-ui.button icon="plus" data-modal-open="#create-category">{{ __('New Category') }}</x-ui.button>
            <x-ui.button :href="route('products.index')" variant="outline" icon="box">{{ __('Products') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-3" style="flex-wrap: wrap;">
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="tag" :label="__('Categories')" :value="\App\Support\Formatter::number($stats['total'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="check-circle" :label="__('Active')" :value="\App\Support\Formatter::number($stats['active'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="box" :label="__('Products')" :value="\App\Support\Formatter::number($stats['products'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="info" :label="__('Uncategorised')" :value="\App\Support\Formatter::number($stats['uncategorised'], 0)" />
        </div>
    </div>

    <div class="card mb-4 mt-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Filters') }}</h2>
            @if ($filters['q'] !== '' || $filters['status'])
                <a href="{{ route('categories.index') }}" class="fs-sm">{{ __('Clear all filters') }}</a>
            @endif
        </div>
        <form method="GET" action="{{ route('categories.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 240px;">
                        <x-ui.input name="q" :label="__('Search')" :value="$filters['q']" :placeholder="__('Name or code')" />
                    </div>
                    <div style="flex: 1 1 170px;">
                        <x-ui.select name="status" :label="__('Status')" :options="['active' => __('Active'), 'inactive' => __('Inactive')]" :value="$filters['status']" :placeholder="__('Any status')" />
                    </div>
                </div>
                <div class="flex gap-2 mt-3">
                    <x-ui.button type="submit" icon="search">{{ __('Apply') }}</x-ui.button>
                </div>
            </div>
        </form>
    </div>

    {{-- data-bulk-scope ties the table's checkboxes to the bar above them. --}}
    <div data-bulk-scope>
    @can('products.manage')
        <x-ui.bulk-form :action="route('bulk.destroy', 'categories')" :subject="__('categories')" />
    @endcan

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="cell-check">
                            <x-ui.checkbox variant="row" data-bulk-all
                                           :label="__('Select every category on this page')" />
                        </th>
                        <th>{{ __('Category') }}</th>
                        <th>{{ __('Parent') }}</th>
                        <th class="num">{{ __('Products') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        <tr>
                            <td class="cell-check">
                                <x-ui.checkbox variant="row" data-bulk-row
                                               value="{{ $category->id }}"
                                               :label="__('Select :name', ['name' => $category->name])" />
                            </td>
                            <td>
                                <div class="fw-medium">{{ $category->name }}</div>
                                @if ($category->code)<div class="fs-xs text-muted font-mono">{{ $category->code }}</div>@endif
                            </td>
                            <td>{{ $category->parent?->name ?? '—' }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::number($category->products_count, 0) }}</td>
                            <td>
                                @if ($category->is_active)
                                    <x-ui.badge type="success">{{ __('Active') }}</x-ui.badge>
                                @else
                                    <x-ui.badge type="default">{{ __('Inactive') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="cell-actions">
                                <div class="flex-center gap-2" style="justify-content: flex-end;">
                                    <x-ui.button variant="ghost" size="sm" icon="edit" data-modal-open="#edit-category-{{ $category->id }}" :aria-label="__('Edit :name', ['name' => $category->name])" />
                                    <x-ui.delete-form :action="route('categories.destroy', $category)"
                                                                 :label="__('Delete :name', ['name' => $category->name])"
                                                                 :confirm="__('Delete this category?')" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state icon="tag" :title="__('No categories found')" :text="__('Create categories to organise your catalog.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {!! $categories->links('partials.pagination') !!}
    <x-ui.modal id="create-category" :title="__('New Category')">
        <form method="POST" action="{{ route('categories.store') }}" data-prevent-double>
            @csrf
            <x-ui.input name="name" :label="__('Category name')" :placeholder="__('e.g. Beverages')" required autofocus />
            <x-ui.input name="code" :label="__('Code')" :placeholder="__('Optional short code')" />
            <x-ui.select name="parent_id" :label="__('Parent category')" :options="$parents" :placeholder="__('Top level')" />
            <div class="form-group">
                <label class="form-label" for="description-new">{{ __('Description') }}</label>
                <textarea name="description" id="description-new" class="form-control" rows="2">{{ old('description') }}</textarea>
            </div>
            <x-ui.checkbox name="is_active" :label="__('Active')" :checked="true" value="1" />
            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ __('Create Category') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    @foreach ($categories as $category)
        <x-ui.modal id="edit-category-{{ $category->id }}" :title="__('Edit :name', ['name' => $category->name])">
            <form method="POST" action="{{ route('categories.update', $category) }}" data-prevent-double>
                @csrf
                @method('PATCH')
                <x-ui.input name="name" :label="__('Category name')" :value="$category->name" required />
                <x-ui.input name="code" :label="__('Code')" :value="$category->code" />
                <x-ui.select name="parent_id" :label="__('Parent category')" :options="$parents->where('id', '!=', $category->id)" :value="$category->parent_id" :placeholder="__('Top level')" />
                <div class="form-group">
                    <label class="form-label" for="description-{{ $category->id }}">{{ __('Description') }}</label>
                    <textarea name="description" id="description-{{ $category->id }}" class="form-control" rows="2">{{ $category->description }}</textarea>
                </div>
                <x-ui.checkbox name="is_active" :label="__('Active')" :checked="$category->is_active" value="1" />
                <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                    <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" icon="check">{{ __('Save Changes') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endforeach
    </div>

</x-app-layout>
