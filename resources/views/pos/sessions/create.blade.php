<x-layouts.app :title="$title">
    <x-ui.page-header
        :title="$title"
        :subtitle="__('Count the drawer float before you start selling')"
        :back="route('pos.index')"
    />

    @include('partials.flash')

    @if ($currentSession)
        <x-ui.alert type="info" :title="__('A session is already open')" dismissible>
            {{ __('Register :register has been open since :time.', [
                'register' => $currentSession->cashRegister?->name ?? '—',
                'time' => \App\Support\Formatter::dateTime($currentSession->opened_at),
            ]) }}
        </x-ui.alert>
    @endif

    <div class="card" style="max-width: 560px;">
        <div class="card-header">
            <h2 class="card-title">{{ __('Session details') }}</h2>
        </div>
        <div class="card-body">
            @if ($registers->isEmpty())
                <x-ui.empty-state
                    icon="calculator"
                    :title="__('No register is available')"
                    :text="__('Every register in this branch already has an open session, or none are active.')"
                />
            @else
                <form method="POST" action="{{ route('pos.sessions.store') }}" data-prevent-double>
                    @csrf

                    <x-ui.select
                        name="cash_register_id"
                        :label="__('Register')"
                        :options="$registers"
                        :placeholder="__('Select a register')"
                        required
                    />

                    <x-ui.input
                        name="opening_balance"
                        type="number"
                        :label="__('Opening float')"
                        value="0.00"
                        min="0"
                        step="0.01"
                        :hint="__('Counted cash placed in the drawer before the first sale.')"
                        required
                    />

                    <x-ui.input name="notes" :label="__('Notes')" :placeholder="__('Optional')" />

                    <div class="flex-center gap-2 mt-4">
                        <x-ui.button variant="primary" type="submit" icon="check-circle" size="lg">{{ __('Open Session') }}</x-ui.button>
                        <x-ui.button variant="ghost" :href="route('pos.index')">{{ __('Cancel') }}</x-ui.button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-layouts.app>
