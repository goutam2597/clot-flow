<x-app-layout>
    <x-slot:title>{{ __('Languages') }} Â· {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Languages')" :subtitle="__('Interface languages. Adding one here makes it selectable in the topbar â€” no template changes required.')" />

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="globe" :label="__('Languages')" :value="\App\Support\Formatter::number($stats['total'])" /></div>
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="check-circle" :label="__('Active')" :value="\App\Support\Formatter::number($stats['active'])" /></div>
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="file" :label="__('Translatable strings')" :value="\App\Support\Formatter::number($stats['strings'])" /></div>
    </div>

    <div class="flex gap-4" style="flex-wrap: wrap; align-items: flex-start;">
        {{-- Existing languages --}}
        <div style="flex: 2 1 460px;">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('Language') }}</th>
                            <th>{{ __('Code / locale') }}</th>
                            <th>{{ __('Direction') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="cell-actions">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($languages as $language)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $language->name }}</div>
                                    @if ($language->native_name && $language->native_name !== $language->name)
                                        <div class="fs-xs text-muted">{{ $language->native_name }}</div>
                                    @endif
                                </td>
                                <td class="font-mono fs-sm">
                                    {{ $language->code }}
                                    <div class="fs-xs text-muted">{{ $language->locale ?: 'â€”' }}</div>
                                </td>
                                <td>
                                    <x-ui.badge type="{{ $language->direction === 'rtl' ? 'warning' : 'default' }}">
                                        {{ $directions[$language->direction] ?? strtoupper($language->direction) }}
                                    </x-ui.badge>
                                </td>
                                <td>
                                    @if ($language->is_default)
                                        <x-ui.badge type="primary" dot>{{ __('Default') }}</x-ui.badge>
                                    @elseif ($language->is_active)
                                        <x-ui.badge type="success">{{ __('Active') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Inactive') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="cell-actions">
                                    <div class="row-actions">
                                        <x-ui.button type="button" variant="ghost" size="sm" icon="globe" class="row-action"
                                                     :href="route('admin.languages.translations', $language)"
                                                     :aria-label="__('Translate :name', ['name' => $language->name])"
                                                     :title="__('Translate')">{{ __('Translate') }}</x-ui.button>

                                        <x-ui.button type="button" variant="ghost" size="sm" icon="edit" class="row-action"
                                                     data-modal-open="#edit-language-{{ $language->id }}"
                                                     :aria-label="__('Edit :name', ['name' => $language->name])"
                                                     :title="__('Edit')">{{ __('Edit') }}</x-ui.button>

                                        @unless ($language->is_default)
                                            <form method="POST" action="{{ route('admin.languages.default', $language) }}" data-prevent-double>
                                                @csrf
                                                <x-ui.button type="submit" variant="ghost" size="sm" icon="check" class="row-action is-default"
                                                             :aria-label="__('Make :name the default language', ['name' => $language->name])"
                                                             :title="__('Default')">{{ __('Default') }}</x-ui.button>
                                            </form>

                                            @if ($language->code !== app()->getLocale())
                                                <x-ui.delete-form :action="route('admin.languages.destroy', $language)"
                                                                             :label="__('Delete')"
                                                                             :confirm="__('Delete the :name language? Its stored translations are removed too.', ['name' => $language->name])"
                                                                             class="row-action is-danger" />
                                            @endif
                                        @endunless
                                    </div>
                                </td>
                            </tr>

                            <x-ui.modal id="edit-language-{{ $language->id }}" :title="__('Edit :name', ['name' => $language->name])">
                                <form method="POST" action="{{ route('admin.languages.update', $language) }}" data-prevent-double>
                                    @csrf
                                    @method('PATCH')
                                    @include('admin.languages._fields', ['language' => $language, 'directions' => $directions])
                                </form>
                                <x-slot:footer>
                                    <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                                </x-slot:footer>
                            </x-ui.modal>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-ui.empty-state icon="globe" :title="__('No languages configured')" :text="__('Add at least one language so the interface has a locale to render in.')" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {!! $languages->links('partials.pagination') !!}
        </div>

        {{-- Add a language --}}
        <div style="flex: 1 1 340px;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Add a language') }}</h2>
                </div>
                <form method="POST" action="{{ route('admin.languages.store') }}" data-prevent-double>
                    @csrf
                    <div class="card-body">
                        @include('admin.languages._fields', ['language' => null, 'directions' => $directions])
                    </div>
                    <div class="card-footer">
                        <x-ui.button type="submit" icon="plus">{{ __('Add language') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
