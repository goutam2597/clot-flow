<x-app-layout>
    <x-slot:title>{{ __('Branches') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Branches & warehouses')" :subtitle="__('Each branch keeps its own stock, registers, staff and reporting. The default branch anchors the system and cannot be removed.')" />

    <div class="flex gap-4" style="flex-wrap: wrap; align-items: flex-start;">
        {{-- Branch list --}}
        <div style="flex: 2 1 460px;">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('Branch') }}</th>
                            <th>{{ __('Contact') }}</th>
                            <th>{{ __('Warehouses') }}</th>
                            <th>{{ __('Staff') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="cell-actions">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($branches as $branch)
                            <tr>
                                <td>
                                    <div class="flex-center gap-2">
                                        <span class="fw-semibold">{{ $branch->name }}</span>
                                        @if ($branch->is_default)
                                            <x-ui.badge type="primary">{{ __('Default') }}</x-ui.badge>
                                        @endif
                                    </div>
                                    <div class="fs-xs text-muted font-mono">{{ $branch->code }}</div>
                                    @if ($branch->address_line || $branch->city)
                                        <div class="fs-xs text-muted">
                                            {{ collect([$branch->address_line, $branch->city, $branch->country])->filter()->implode(', ') }}
                                        </div>
                                    @endif
                                </td>
                                <td class="fs-sm">
                                    @if ($branch->phone)<div>{{ $branch->phone }}</div>@endif
                                    @if ($branch->email)<div class="text-muted">{{ $branch->email }}</div>@endif
                                    @if (! $branch->phone && ! $branch->email)<span class="text-muted">—</span>@endif
                                </td>
                                <td>
                                    @if ($branch->warehouses->isEmpty())
                                        <span class="text-muted">{{ __('None') }}</span>
                                    @else
                                        <div class="flex-center gap-2" style="flex-wrap: wrap;">
                                            @foreach ($branch->warehouses as $warehouse)
                                                <x-ui.badge type="{{ $warehouse->is_default ? 'primary' : 'default' }}">{{ $warehouse->name }}</x-ui.badge>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="financial">{{ $branch->users_count }}</td>
                                <td>
                                    <x-ui.badge :type="$branch->is_active ? 'success' : 'default'" dot>
                                        {{ $branch->is_active ? __('Active') : __('Inactive') }}
                                    </x-ui.badge>
                                </td>
                                <td class="cell-actions">
                                    <div class="flex-center gap-2" style="justify-content: flex-end;">
                                        <x-ui.button type="button" variant="ghost" size="sm" icon="edit" class="table-actions-btn"
                                                     data-modal-open="#edit-branch-{{ $branch->id }}"
                                                     :aria-label="__('Edit :name', ['name' => $branch->name])" />
                                    </div>
                                </td>
                            </tr>

                            <x-ui.modal id="edit-branch-{{ $branch->id }}" :title="__('Edit :name', ['name' => $branch->name])">
                                <form method="POST" action="{{ route('branches.update', $branch) }}" data-prevent-double>
                                    @csrf
                                    @method('PATCH')
                                    @include('branches._fields', ['branch' => $branch])
                                </form>
                                <x-slot:footer>
                                    <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                                </x-slot:footer>
                            </x-ui.modal>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state icon="briefcase" :title="__('No branches yet')" :text="__('Add a branch to start splitting stock, staff and reporting.')" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Add branch --}}
        <div style="flex: 1 1 340px;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Add a branch') }}</h2>
                </div>
                <form method="POST" action="{{ route('branches.store') }}" data-prevent-double>
                    @csrf
                    <div class="card-body">
                        @include('branches._fields', ['branch' => null])
                        <p class="form-hint mt-2">
                            {{ __('A main warehouse is created automatically for every new branch.') }}
                        </p>
                    </div>
                    <div class="card-footer">
                        <x-ui.button type="submit" icon="plus">{{ __('Create branch') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
