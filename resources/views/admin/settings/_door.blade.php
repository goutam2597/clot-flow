{{--
    A settings tab that is really a door to a dedicated screen.

    Languages have a translation editor, tax rates a full list with rates and
    product links - embedding either inside a tab would be a page inside a page
    inside a tab. The card says what is over there and why, which is the
    honest choice.
--}}

<div class="grid gap-4">
    <x-ui.settings-panel :title="__('Languages')" :subtitle="__('The languages this business trades in, and the translation editor.')">
        <div class="flex-between gap-4">
            <div>
                <div class="fs-sm fw-semibold">{{ __('Manage languages') }}</div>
                <div class="fs-sm text-muted mt-1">
                    {{ __('Add a language, set the default, and translate the interface without leaving the editor.') }}
                </div>
            </div>
            <x-ui.button variant="secondary" icon="arrow-right"
                         href="{{ route('admin.languages.index') }}">{{ __('Open editor') }}</x-ui.button>
        </div>
    </x-ui.settings-panel>

    <x-ui.settings-panel :title="__('Tax rates')" :subtitle="__('Rates applied to sales and purchases.')">
        <div class="flex-between gap-4">
            <div>
                <div class="fs-sm fw-semibold">{{ __('Default tax rate') }}</div>
                <div class="fs-sm text-muted mt-1">
                    {{ __('The rate applied to new products when none is chosen. The full list lives in the point of sale, where products link to rates.') }}
                </div>
            </div>
            <span class="fs-sm text-muted">{{ $taxDefault ?? '—' }}</span>
        </div>
    </x-ui.settings-panel>
</div>
