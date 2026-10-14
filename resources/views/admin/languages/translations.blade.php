<x-app-layout>
    <x-slot:title>{{ __('Translations') }} · {{ $language->name }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <div class="flex-center gap-3 mb-4" style="flex-wrap: wrap; justify-content: space-between;">
        <div class="min-w-0">
            <div class="flex-center gap-2">
                <h1 class="page-header-title">{{ __('Translations') }}</h1>
                <x-ui.badge type="{{ $language->direction === 'rtl' ? 'warning' : 'default' }}">{{ strtoupper($language->code) }}</x-ui.badge>
            </div>
            <p class="page-header-subtitle">{{ __('Translate every interface string for :name. Empty values fall back to English.', ['name' => $language->name]) }}</p>
        </div>
        <div class="flex-center gap-2">
            <span class="badge badge-primary" data-translate-count>{{ $stats['translated'] }} / {{ $stats['total'] }}</span>
            <x-ui.button type="button" variant="outline" size="sm" icon="arrow-left" :href="route('admin.languages.index')">
                {{ __('Back to languages') }}
            </x-ui.button>
        </div>
    </div>

    <div class="max-w-4xl">
        {{--
            One row per translatable string: the English source on the left,
            the language's value on the right. Rows are all one form, so a
            single Save commits the whole page; the search filters rows as
            you type without losing anything unsaved (hiding is not editing).
        --}}
        <form method="POST" action="{{ route('admin.languages.translations.save', $language) }}" data-prevent-double>
            @csrf

            <div class="translation-toolbar">
                <x-ui.input name="q" type="search" :placeholder="__('Search a string to translate…')" class="flex-grow"
                            data-translate-search autocomplete="off" />
                <x-ui.button type="submit" icon="check">{{ __('Save translations') }}</x-ui.button>
            </div>

            {{-- Strings that exist in views but not in the source file — a
                 view calling __() with a key en.json never lists — can still
                 be translated: add the key by hand, it lands in the file with
                 everything else. --}}
            <div class="flex-center gap-2 mb-4" style="flex-wrap: wrap;">
                <input type="text" class="form-control" style="max-width: 380px;"
                       data-add-key placeholder="{{ __('New key, exactly as written in the view') }}"
                       autocomplete="off">
                <button type="button" class="btn btn-secondary" data-add-row>
                    <span>{{ __('Add a string') }}</span>
                </button>
                <span class="form-hint">{{ __('For strings a view uses that this list does not include.') }}</span>
            </div>

            <div class="settings-panel" data-translation-rows>
                @foreach ($entries as $entry)
                    <div class="translation-row @unless ($entry['translated']) translation-row-missing @endunless"
                         data-translation-row data-haystack="{{ mb_strtolower($entry['key'] . ' ' . $entry['source']) }}">
                        <div class="translation-key">
                            <code class="translation-key-name">{{ $entry['key'] }}</code>
                            @unless ($entry['translated'])
                                <span class="badge badge-warning">{{ __('Untranslated') }}</span>
                            @endif
                            <div class="translation-source">{{ $entry['source'] }}</div>
                        </div>
                        <div class="translation-value">
                            <textarea name="t[{{ $entry['key'] }}]" rows="1"
                                      class="form-control @unless ($entry['translated']) is-missing @endunless"
                                      dir="{{ $language->direction }}"
                                      placeholder="{{ $entry['source'] }}">{{ $entry['value'] }}</textarea>
                        </div>
                    </div>
                @endforeach
            </div>
        </form>
    </div>

    

    @push('scripts')
    <script>
        (function () {
            var search = document.querySelector('[data-translate-search]');
            var rows = document.querySelectorAll('[data-translation-row]');
            var rowsBox = document.querySelector('[data-translation-rows]');
            var addKey = document.querySelector('[data-add-key]');
            var addBtn = document.querySelector('[data-add-row]');
            var direction = document.documentElement.getAttribute('dir') === 'rtl' ? 'rtl' : 'ltr';

            // Grow textareas with their content — but progressively: growing
            // 1,300+ textareas in one pass at load makes the whole page jump
            // (the "flicker" on refresh). Focus and typing grow the one
            // being edited; untouched rows scroll within their row.
            function wire(row) {
                var area = row.querySelector('textarea');
                if (!area || row.dataset.wired === '1') { return; }
                row.dataset.wired = '1';
                var grow = function () {
                    area.style.height = 'auto';
                    area.style.height = Math.min(160, 38 + area.scrollHeight) + 'px';
                };
                area.addEventListener('focus', grow);
                area.addEventListener('input', grow);
            }

            Array.prototype.forEach.call(document.querySelectorAll('[data-translation-row]'), wire);
            Array.prototype.forEach.call(document.querySelectorAll('[data-add-key]'), function () {});

            // "Add a string": a view uses a key this list does not include.
            // The row joins the top of the list and saves with everything else.
            if (addBtn && addKey) {
                addBtn.addEventListener('click', function () {
                    var key = addKey.value.trim();
                    if (key === '') { addKey.focus(); return; }
                    if (rowsBox.querySelector('textarea[name="t[' + key + ']"]')) { return; }

                    var row = document.createElement('div');
                    row.className = 'translation-row translation-row-missing';
                    row.setAttribute('data-translation-row', '');
                    row.setAttribute('data-haystack', key.toLowerCase());

                    var keyCell = document.createElement('div');
                    keyCell.className = 'translation-key';
                    var code = document.createElement('code');
                    code.className = 'translation-key-name';
                    code.textContent = key;
                    var badge = document.createElement('span');
                    badge.className = 'badge badge-warning';
                    badge.textContent = @json(__('Untranslated'));
                    keyCell.appendChild(code);
                    keyCell.appendChild(badge);
                    var source = document.createElement('div');
                    source.className = 'translation-source';
                    source.textContent = key;
                    keyCell.appendChild(source);

                    var valueCell = document.createElement('div');
                    valueCell.className = 'translation-value';
                    var area = document.createElement('textarea');
                    area.name = 't[' + key + ']';
                    area.rows = 1;
                    area.className = 'form-control is-missing';
                    area.dir = direction;
                    valueCell.appendChild(area);

                    row.appendChild(keyCell);
                    row.appendChild(valueCell);
                    rowsBox.prepend(row);
                    wire(row);
                    area.focus();
                    addKey.value = '';
                });
            }

            if (search) {
                search.addEventListener('input', function () {
                    var q = search.value.trim().toLowerCase();
                    var live = document.querySelectorAll('[data-translation-row]');
                    Array.prototype.forEach.call(live, function (row) {
                        row.hidden = q !== '' && row.getAttribute('data-haystack').indexOf(q) === -1;
                    });
                });
            }
        })();
    </script>
    @endpush
</x-app-layout>
