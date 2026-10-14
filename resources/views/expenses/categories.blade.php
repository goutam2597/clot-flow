<x-app-layout>
    <x-slot:title>{{ __('Expense categories') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Expense categories')" :subtitle="__('Grouping keeps the profit and loss report readable as the business grows.')"
                     :back="route('expenses.index')" />

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 220px;">
            <x-ui.stat-card icon="tag" :label="__('Categories')" :value="\App\Support\Formatter::number($categories->total())" />
        </div>
        <div style="flex: 1 1 220px;">
            <x-ui.stat-card icon="wallet" :label="__('All expenses, all time')" :value="\App\Support\Formatter::money($totalExpenses)" />
        </div>
    </div>

    <div class="flex gap-4" style="flex-wrap: wrap; align-items: flex-start;">
        <div style="flex: 2 1 440px;">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('Category') }}</th>
                            <th>{{ __('Code') }}</th>
                            <th class="num">{{ __('Expenses') }}</th>
                            <th class="num">{{ __('Total') }}</th>
                            <th>{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($categories as $category)
                            <tr>
                                <td>
                                    <div class="fw-medium">{{ $category->name }}</div>
                                    @if ($category->description)
                                        <div class="fs-xs text-muted">{{ $category->description }}</div>
                                    @endif
                                </td>
                                <td class="font-mono fs-sm">{{ $category->code ?: '—' }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::number($category->expenses_count) }}</td>
                                <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($category->expenses_sum ?? 0) }}</td>
                                <td>
                                    <x-ui.badge :type="$category->is_active ? 'success' : 'default'" dot>
                                        {{ $category->is_active ? __('Active') : __('Inactive') }}
                                    </x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-ui.empty-state icon="tag" :title="__('No categories yet')"
                                                    :text="__('Add a category such as Rent, Utilities or Salaries so expenses can be reported by type.')" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {!! $categories->links('partials.pagination') !!}
        </div>

        @can('expenses.manage')
            <div style="flex: 1 1 320px;">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">{{ __('Add a category') }}</h2></div>
                    <form method="POST" action="{{ route('expenses.categories.store') }}" data-prevent-double>
                        @csrf
                        <div class="card-body">
                            <x-ui.input name="name" :label="__('Name')" required placeholder="e.g. Utilities" />
                            <x-ui.input name="code" :label="__('Code')" placeholder="UTIL" />
                            <x-ui.input name="description" :label="__('Description')" />
                            <div class="form-group mb-0">
                                <input type="hidden" name="is_active" value="0">
                                {{-- The switch component: a hand-rolled track here can never
                                     work, because the input is buried inside the
                                     component and `input:checked + track` needs siblings. --}}
                                <x-ui.checkbox variant="switch" name="is_active" value="1"
                                               :label="__('Active')" :checked="true" />
                            </div>
                        </div>
                        <div class="card-footer">
                            <x-ui.button type="submit" icon="plus">{{ __('Add category') }}</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    </div>
</x-app-layout>
