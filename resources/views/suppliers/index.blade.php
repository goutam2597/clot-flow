<x-app-layout>
    <x-slot:title>{{ __('Suppliers') }} Â· {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Suppliers')" :subtitle="__('Who supplies your stock, with what you still owe them.')">
        <x-slot:actions>
            @can('suppliers.manage')
                <x-ui.button icon="plus" data-modal-open="#create-supplier">{{ __('New Supplier') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-3" style="flex-wrap: wrap;">
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="supplier" :label="__('Suppliers')" :value="\App\Support\Formatter::number($stats['total'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="check-circle" :label="__('Active')" :value="\App\Support\Formatter::number($stats['active'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="credit-card" :label="__('Suppliers with dues')" :value="\App\Support\Formatter::number($stats['with_dues'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="money" :label="__('Outstanding')" :value="\App\Support\Formatter::money($stats['payables'])" />
        </div>
    </div>

    <div class="card mb-4 mt-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Filters') }}</h2>
            @if ($filters['q'] !== '' || $filters['status'] || $filters['dues'])
                <a href="{{ route('suppliers.index') }}" class="fs-sm">{{ __('Clear all filters') }}</a>
            @endif
        </div>
        <form method="GET" action="{{ route('suppliers.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 240px;">
                        <x-ui.input name="q" :label="__('Search')" :value="$filters['q']" :placeholder="__('Name, company, phone or code')" />
                    </div>
                    <div style="flex: 1 1 140px;">
                        <x-ui.select name="status" :label="__('Status')" :options="['active' => __('Active'), 'inactive' => __('Inactive')]" :value="$filters['status']" :placeholder="__('Any status')" />
                    </div>
                    <div style="flex: 1 1 140px;">
                        <x-ui.select name="dues" :label="__('Dues')" :options="['due' => __('Has dues')]" :value="$filters['dues']" :placeholder="__('All')" />
                    </div>
                </div>
                <div class="flex gap-2 mt-3">
                    <x-ui.button type="submit" icon="search">{{ __('Apply') }}</x-ui.button>
                </div>
            </div>
        </form>
    </div>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Supplier') }}</th>
                        <th class="num">{{ __('Purchases') }}</th>
                        <th class="num">{{ __('Outstanding') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppliers as $supplier)
                        <tr>
                            <td>
                                <div class="fw-medium">{{ $supplier->name }}</div>
                                <div class="fs-xs text-muted">{{ $supplier->company ?? $supplier->phone ?? '' }}</div>
                            </td>
                            <td class="num financial">{{ \App\Support\Formatter::number($supplier->purchases_count ?? 0, 0) }}</td>
                            <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($supplier->outstanding_payable ?? 0) }}</td>
                            <td>
                                @if ($supplier->is_active)
                                    <x-ui.badge type="success">{{ __('Active') }}</x-ui.badge>
                                @else
                                    <x-ui.badge type="default">{{ __('Inactive') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="cell-actions">
                                @can('suppliers.manage')
                                    <div class="flex-center gap-2" style="justify-content: flex-end;">
                                        <x-ui.button variant="ghost" size="sm" icon="edit" data-modal-open="#edit-supplier-{{ $supplier->id }}" :aria-label="__('Edit :name', ['name' => $supplier->name])" />
                                        <x-ui.delete-form :action="route('suppliers.destroy', $supplier)"
                                                                     :label="__('Delete :name', ['name' => $supplier->name])"
                                                                     :confirm="__('Delete this supplier?')" />
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state icon="supplier" :title="__('No suppliers found')" :text="__('Add suppliers to track purchases and payables.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {!! $suppliers->links('partials.pagination') !!}
    @can('suppliers.manage')
        <x-ui.modal id="create-supplier" :title="__('New Supplier')">
            <form method="POST" action="{{ route('suppliers.store') }}" data-prevent-double>
                @csrf
                @include('suppliers.form', ['supplier' => null])
                <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                    <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" icon="check">{{ __('Create Supplier') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        @foreach ($suppliers as $supplier)
            <x-ui.modal id="edit-supplier-{{ $supplier->id }}" :title="__('Edit :name', ['name' => $supplier->name])">
                <form method="POST" action="{{ route('suppliers.update', $supplier) }}" data-prevent-double>
                    @csrf
                    @method('PATCH')
                    @include('suppliers.form', ['supplier' => $supplier])
                    <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                        <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                        <x-ui.button type="submit" icon="check">{{ __('Save Changes') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endforeach
    @endcan

</x-app-layout>
