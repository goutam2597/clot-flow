<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :subtitle="__('Tills available to this branch and their live drawer status')">
        <x-slot:actions>
            <x-ui.button variant="primary" icon="plus" data-modal-open="#create-register">{{ __('New Register') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('partials.flash')

    <div class="table-wrap">
        @if ($registers->isEmpty())
            <div class="table-empty">
                <x-ui.empty-state
                    icon="calculator"
                    :title="__('No cash registers yet')"
                    :text="__('Create a register so cashiers can open a session and take payments.')"
                />
            </div>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('Register') }}</th>
                            <th>{{ __('Code') }}</th>
                            <th>{{ __('Warehouse') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Open Session') }}</th>
                            <th>{{ __('Last Session') }}</th>
                            <th class="cell-actions">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($registers as $row)
                            @php
                                $register = $row['register'];
                                $openSession = $row['openSession'];
                                $lastSession = $row['lastSession'];
                            @endphp
                            <tr>
                                <td class="fw-medium">{{ $register->name }}</td>
                                <td class="font-mono fs-sm">{{ $register->code }}</td>
                                <td>{{ $register->warehouse?->name ?? '—' }}</td>
                                <td>
                                    @if ($register->is_active)
                                        <x-ui.badge type="success" :dot="true">{{ __('Active') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge type="default">{{ __('Inactive') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td>
                                    @if ($openSession)
                                        <x-ui.badge type="warning" :dot="true">{{ __('Open') }}</x-ui.badge>
                                        <div class="fs-sm text-muted">
                                            {{ $openSession->user?->name ?? '—' }} ·
                                            {{ \App\Support\Formatter::dateTime($openSession->opened_at) }}
                                        </div>
                                        <div class="fs-sm financial">
                                            {{ __('Float') }}: {{ \App\Support\Formatter::money($openSession->opening_balance) }}
                                        </div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($lastSession)
                                        <div class="fs-sm">{{ \App\Support\Formatter::date($lastSession->opened_at) }}</div>
                                        <div class="fs-sm financial text-muted">
                                            {{ \App\Support\Formatter::money($lastSession->total_sales) }}
                                        </div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="cell-actions">
                                    <div class="flex-center gap-2" style="justify-content: flex-end;">
                                        <x-ui.button variant="ghost" size="sm" icon="edit" data-modal-open="#edit-register-{{ $register->id }}">
                                            {{ __('Edit') }}
                                        </x-ui.button>

                                        <x-ui.delete-form :action="route('pos.registers.destroy', $register)"
                                                                 size="sm"
                                                                 :label="__('Delete :name', ['name' => $register->name])"
                                                                 :confirm="__('Deactivate or delete this register?')" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Create register --}}
    <x-ui.modal id="create-register" :title="__('New Cash Register')">
        <form method="POST" action="{{ route('pos.registers.store') }}" data-prevent-double>
            @csrf

            <x-ui.input name="name" :label="__('Register name')" :placeholder="__('Counter 1')" required autofocus />
            <x-ui.input name="code" :label="__('Code')" :placeholder="__('REG01')" :hint="__('Unique short code, e.g. REG01')" required />
            <x-ui.select
                name="warehouse_id"
                :label="__('Warehouse')"
                :options="$warehouses"
                :placeholder="__('No specific warehouse')"
            />
            <x-ui.checkbox name="is_active" :label="__('Active')" :checked="true" value="1" />

            <div class="flex-center gap-2 mt-4" style="justify-content: flex-end;">
                <x-ui.button variant="outline" type="button" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="primary" type="submit" icon="check-circle">{{ __('Create Register') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Edit register --}}
    @foreach ($registers as $row)
        @php $register = $row['register']; @endphp
        <x-ui.modal id="edit-register-{{ $register->id }}" :title="__('Edit :name', ['name' => $register->name])">
            <form method="POST" action="{{ route('pos.registers.update', $register) }}" data-prevent-double>
                @csrf
                @method('PATCH')

                <x-ui.input name="name" :label="__('Register name')" :value="$register->name" required />
                <x-ui.input name="code" :label="__('Code')" :value="$register->code" required />
                <x-ui.select
                    name="warehouse_id"
                    :label="__('Warehouse')"
                    :options="$warehouses"
                    :value="$register->warehouse_id"
                    :placeholder="__('No specific warehouse')"
                />
                <x-ui.checkbox name="is_active" :label="__('Active')" :checked="$register->is_active" value="1" />

                <div class="flex-center gap-2 mt-4" style="justify-content: flex-end;">
                    <x-ui.button variant="outline" type="button" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button variant="primary" type="submit" icon="check-circle">{{ __('Save Changes') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endforeach
</x-layouts.app>
