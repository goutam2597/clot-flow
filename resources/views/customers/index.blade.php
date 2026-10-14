<x-app-layout>
    <x-slot:title>{{ __('Customers') }} Â· {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Customers')" :subtitle="__('Everyone you sell to, with what they still owe.')">
        <x-slot:actions>
            @can('customers.manage')
                <x-ui.button icon="plus" data-modal-open="#create-customer">{{ __('New Customer') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-3" style="flex-wrap: wrap;">
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="users" :label="__('Customers')" :value="\App\Support\Formatter::number($stats['total'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="check-circle" :label="__('Active')" :value="\App\Support\Formatter::number($stats['active'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="credit-card" :label="__('Customers with dues')" :value="\App\Support\Formatter::number($stats['with_dues'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="money" :label="__('Outstanding')" :value="\App\Support\Formatter::money($stats['outstanding'])" />
        </div>
    </div>
    <div class="card mb-4 mt-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Filters') }}</h2>
            @if ($filters['q'] !== '' || $filters['type'] || $filters['status'] || $filters['dues'])
                <a href="{{ route('customers.index') }}" class="fs-sm">{{ __('Clear all filters') }}</a>
            @endif
        </div>
        <form method="GET" action="{{ route('customers.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 240px;">
                        <x-ui.input name="q" :label="__('Search')" :value="$filters['q']" :placeholder="__('Name, phone, email or code')" />
                    </div>
                    <div style="flex: 1 1 150px;">
                        <x-ui.select name="type" :label="__('Type')" :options="$customerTypes" :value="$filters['type']" :placeholder="__('Any type')" />
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
                        <th>{{ __('Customer') }}</th>
                        <th>{{ __('Type') }}</th>
                        <th class="num">{{ __('Sales') }}</th>
                        <th class="num">{{ __('Outstanding') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td>
                                <div class="fw-medium">{{ $customer->name }}</div>
                                <div class="fs-xs text-muted">{{ $customer->phone ?? $customer->email ?? '' }}</div>
                            </td>
                            <td>{{ $customerTypes[$customer->type] ?? $customer->type }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::number($customer->sales_count ?? 0, 0) }}</td>
                            <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($customer->outstanding_balance ?? 0) }}</td>
                            <td>
                                @if ($customer->is_active)
                                    <x-ui.badge type="success">{{ __('Active') }}</x-ui.badge>
                                @else
                                    <x-ui.badge type="default">{{ __('Inactive') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="cell-actions">
                                @can('customers.manage')
                                    <div class="flex-center gap-2" style="justify-content: flex-end;">
                                        <x-ui.button variant="ghost" size="sm" icon="edit" data-modal-open="#edit-customer-{{ $customer->id }}" :aria-label="__('Edit :name', ['name' => $customer->name])" />
                                        <x-ui.delete-form :action="route('customers.destroy', $customer)"
                                                                     :label="__('Delete :name', ['name' => $customer->name])"
                                                                     :confirm="__('Delete this customer?')" />
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state icon="users" :title="__('No customers found')" :text="__('Add customers to track dues and repeat business.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {!! $customers->links('partials.pagination') !!}
    @can('customers.manage')
        <x-ui.modal id="create-customer" :title="__('New Customer')">
            <form method="POST" action="{{ route('customers.store') }}" data-prevent-double>
                @csrf
                @include('customers.form', ['customer' => null])
                <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                    <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" icon="check">{{ __('Create Customer') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        @foreach ($customers as $customer)
            <x-ui.modal id="edit-customer-{{ $customer->id }}" :title="__('Edit :name', ['name' => $customer->name])">
                <form method="POST" action="{{ route('customers.update', $customer) }}" data-prevent-double>
                    @csrf
                    @method('PATCH')
                    @include('customers.form', ['customer' => $customer])
                    <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                        <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                        <x-ui.button type="submit" icon="check">{{ __('Save Changes') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endforeach
    @endcan

</x-app-layout>
