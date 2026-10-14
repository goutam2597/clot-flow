<x-app-layout>
    <x-slot:title>{{ __('Business settings') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Business settings')" :subtitle="__('These values drive numbering, tax behaviour, printed documents and the POS rules.')" />

    {{--
        Brand logo.

        Its own form, outside the settings form below: HTML forbids a form
        inside a form, and a file upload never belonged to the field table
        anyway — it has its own validation, its own success state, and its
        own reason to exist (replacing the letter mark with the owner's
        actual logo on the sign-in page and the sidebar).
    --}}
    @php
        $logoType = \App\Support\BrandLogo::type();
        $logoSvg = $logoType === 'svg' ? \App\Support\BrandLogo::svg() : null;
        $logoPngUrl = $logoType === 'png' ? \App\Support\BrandLogo::url() : null;
        $hasLogo = (bool) ($logoSvg || $logoPngUrl);
    @endphp
    <div class="max-w-2xl mb-4">
        <x-ui.settings-panel :title="__('Brand logo')">
            <div class="brand-logo-manager" data-logo-form>
                <div class="brand-logo-preview" data-logo-preview>
                    @if ($logoSvg)
                        <span class="brand-logo brand-logo-settings">{!! $logoSvg !!}</span>
                    @elseif ($logoPngUrl)
                        <img src="{{ $logoPngUrl }}" alt="">
                    @endif
                    <span class="brand-logo-fallback" data-logo-placeholder @if ($hasLogo) hidden @endif>
                        <x-ui.icon name="image" :size="26" />
                    </span>
                </div>

                <div class="brand-logo-controls">
                    <form method="POST" action="{{ route('admin.settings.logo') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="flex gap-2">
                            <label class="btn btn-secondary">
                                <x-ui.icon name="upload" :size="15" />
                                <span data-logo-file-label>{{ $hasLogo ? __('Replace logo') : __('Upload logo') }}</span>
                                <input type="file" name="logo" accept=".svg,.png,image/svg+xml,image/png" data-logo-input class="visually-hidden">
                            </label>

                            @if ($hasLogo)
                                <button type="submit" formaction="{{ route('admin.settings.logo.remove') }}"
                                    class="btn btn-danger-ghost" data-logo-remove>
                                    {{ __('Remove') }}
                                </button>
                            @endif
                        </div>
                        <span class="form-hint" data-logo-filename></span>
                    </form>

                    <div class="form-hint">
                        {{ __('Picking a file uploads it immediately — no save button. An SVG logo replaces the letter mark and the business name on the sign-in page and the sidebar, and follows the light / dark mode automatically. A PNG keeps its exact colours in every mode.') }}
                    </div>

                    @error('logo')
                        <div class="form-error">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </x-ui.settings-panel>
    </div>


    {{--
        One form, one tab per group.

        The reference gives each settings group its own tab and keeps the save
        action visible. Twenty-nine fields on one scroll means a changed POS rule
        is discovered only after hunting for the button that commits it, and a
        tab per group also means the fields that matter for the task at hand are
        the ones on screen.

        Sections that are really doors to dedicated screens (languages, tax
        rates, currencies) say so instead of duplicating an editor inside a tab
        -- the reference is explicit that a page inside a page inside a tab is
        worse than a link.
    --}}
    <form method="POST" action="{{ route('admin.settings.update') }}" data-prevent-double data-settings-form>
        @csrf
        <input type="hidden" name="_tab" value="">

        <div class="max-w-2xl">
            <x-ui.settings-tabs :tabs="$tabs" :active="$tabs[0]['value'] ?? null">

                @foreach ($tabs as $tab)
                    @php $fields = $groups[$tab['label']] ?? []; @endphp

                    <div data-tab-panel="{{ $tab['value'] }}">
                        @if ($fields)
                            <x-ui.settings-panel :title="$tab['label']">
                                <div class="settings-grid-2">
                                    @foreach ($fields as $field)
                                        @include('admin.settings._field', ['field' => $field])
                                    @endforeach
                                </div>
                            </x-ui.settings-panel>
                        @else
                            @include('admin.settings._door')
                        @endif
                    </div>
                @endforeach

            </x-ui.settings-tabs>

            {{--
                The save bar.

                Pins to the bottom of the content, shows a dirty count only when
                something has actually changed, and Discard is present only then
                -- the reference's bar is empty when the form is clean, and a
                Discard button beside an untouched form is an invitation to throw
                away a value someone else just saved.
            --}}
            <div class="settings-save-bar" data-save-bar hidden>
                <span class="badge badge-primary" data-dirty-count></span>
                <span class="fs-sm text-muted" data-dirty-label>{{ __('unsaved change') }}</span>
                <span class="flex-grow"></span>
                <x-ui.button type="button" variant="outline" data-discard>{{ __('Discard changes') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ __('Save settings') }}</x-ui.button>
            </div>
        </div>
    </form>

    @push('scripts')
    {{--
        Tabs and the dirty state.

        Tabs swap panels in place; the form is still one form, so every tab's
        fields submit together. `_tab` remembers which tab the validation
        redirect should land on. The dirty counter watches every control, and
        the unsaved-changes warning is scoped to this form: the reference warns
        before leaving with unsaved edits, and that is exactly what a tab bar
        needs.
    --}}
    <script>
        (function () {
            var form = document.querySelector('[data-settings-form]');
            if (!form) { return; }

            /* Localised strings for the dirty counter. These live here rather
               than in app.js because app.js is a static file and cannot carry
               Blade syntax - the json_encode calls broke its parse. */
            Meridian.labels = {
                unsavedSingular: @json(__('unsaved change')),
                unsavedPlural: @json(__('unsaved changes')),
                settingsSaved: @json(__('Settings saved successfully.'))
            };

            var tabInput = form.querySelector('input[name="_tab"]');
            var panels = Array.from(form.querySelectorAll('[data-tab-panel]'));
            var tabs = Array.from(form.querySelectorAll('[data-tab-target]'));
            var dirty = false;
            var dirtyCount = 0;
            var bar = form.querySelector('[data-save-bar]');
            var counter = form.querySelector('[data-dirty-count]');
            var label = form.querySelector('[data-dirty-label]');
            var discard = form.querySelector('[data-discard]');

            function activate(value) {
                tabInput.value = value;
                panels.forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-tab-panel') !== value;
                });
                tabs.forEach(function (tab) {
                    var on = tab.getAttribute('data-tab-target') === value;
                    tab.classList.toggle('active', on);
                    tab.setAttribute('aria-selected', on ? 'true' : 'false');
                });
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    activate(tab.getAttribute('data-tab-target'));
                });
            });

            // Land on the tab the controller remembered (validation redirect).
            activate(tabInput.value || (tabs[0] ? tabs[0].getAttribute('data-tab-target') : ''));

            // Dirty state: count each control once. A checkbox counts once per
            // toggle, not once per click, because the bar's number is read as
            // "how many things did I change".
            var counted = new WeakSet();
            var resetting = false;

            function readValue(el) {
                if (el.type === 'checkbox') { return el.checked ? '1' : '0'; }
                if (el.type === 'radio') { return el.checked ? el.value : ''; }
                return el.value;
            }

            Array.from(form.querySelectorAll('input, select, textarea')).forEach(function (el) {
                if (el.name === '_token' || el.name === '_tab') { return; }
                el._initialValue = readValue(el);
                el.addEventListener('input', function () {
                    if (resetting) { return; }
                    var now = readValue(el);
                    if (now === el._initialValue) {
                        if (counted.has(el)) { counted.delete(el); dirtyCount = Math.max(0, dirtyCount - 1); }
                        if (dirtyCount === 0) { dirty = false; }
                        render();
                        return;
                    }
                    if (!counted.has(el)) { counted.add(el); dirtyCount += 1; }
                    dirty = true;
                    render();
                });
                el.addEventListener('change', function () {
                    if (resetting) { return; }
                    var now = readValue(el);
                    if (now === el._initialValue) {
                        if (counted.has(el)) { counted.delete(el); dirtyCount = Math.max(0, dirtyCount - 1); }
                        if (dirtyCount === 0) { dirty = false; }
                        render();
                        return;
                    }
                    if (!counted.has(el)) { counted.add(el); dirtyCount += 1; }
                    dirty = true;
                    render();
                });
            });

            function render() {
                if (!dirty) {
                    bar.hidden = true;
                    return;
                }
                bar.hidden = false;
                counter.textContent = String(dirtyCount);
                label.textContent = dirtyCount === 1
                    ? Meridian.labels.unsavedSingular
                    : Meridian.labels.unsavedPlural.replace('{count}', String(dirtyCount));
            }

            if (discard) {
                discard.addEventListener('click', function () {
                    // Restore each control to its initial value, then recompute
                    // dirty state from scratch rather than trusting a flag.
                    // Checkbox change events fire asynchronously - after this
                    // handler - so any suppression window is a race; comparing
                    // every control against its initial value is not.
                    Array.from(form.querySelectorAll('input, select, textarea')).forEach(function (el) {
                        if (el.name === '_token' || el.name === '_tab') { return; }
                        var initial = el._initialValue;
                        if (initial === undefined) { return; }
                        if (el.type === 'checkbox') {
                            el.checked = initial === '1';
                        } else if (el.type === 'radio') {
                            el.checked = el.value === initial;
                        } else if (el.tagName === 'SELECT') {
                            var idx = Array.from(el.options).findIndex(function (o) {
                                return o.value === initial;
                            });
                            el.selectedIndex = idx < 0 ? 0 : idx;
                        } else {
                            el.value = initial;
                        }
                    });

                    // Recompute: any control that still differs from its initial
                    // value is genuinely dirty; everything else is clean.
                    counted.clear();
                    dirtyCount = 0;
                    Array.from(form.querySelectorAll('input, select, textarea')).forEach(function (el) {
                        if (el.name === '_token' || el.name === '_tab') { return; }
                        if (readValue(el) !== el._initialValue) {
                            counted.add(el);
                            dirtyCount += 1;
                        }
                    });
                    dirty = dirtyCount > 0;
                    render();
                });
            }

            // Warn before leaving with unsaved edits -- but not on the submit
            // button itself, which is the one place leaving is the point.
            window.addEventListener('beforeunload', function (e) {
                if (!dirty) { return; }
                e.preventDefault();
                e.returnValue = '';
            });
        })();
    </script>
    @endpush

    @push('scripts')
    <script>
        /* Logo upload: choosing a file IS the save. The form submits itself
           the moment a file is picked and the page reloads with the logo
           stored -- there is no extra save button to forget.
           The preview shows the chosen file as an <img> for the split second
           before the navigation, whatever the previous logo was (an inline
           SVG or a PNG img); once stored, the logo renders everywhere:
           inline and theme-aware for SVG, exactly as uploaded for PNG. */
        (function () {
            var root = document.querySelector('[data-logo-form]');
            if (!root) { return; }

            var form = root.querySelector('form');
            var input = root.querySelector('[data-logo-input]');
            var preview = root.querySelector('[data-logo-preview]');
            var placeholder = root.querySelector('[data-logo-placeholder]');
            var filename = root.querySelector('[data-logo-filename]');
            var fileLabel = root.querySelector('[data-logo-file-label]');

            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) { return; }

                // Whatever is currently shown -- an inline SVG logo or a PNG
                // img -- yields to the freshly chosen file.
                Array.prototype.forEach.call(preview.children, function (el) {
                    if (!el.hasAttribute('data-logo-placeholder')) {
                        preview.removeChild(el);
                    }
                });
                placeholder.hidden = true;

                var img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.alt = '';
                img.className = 'brand-logo-chosen';
                img.setAttribute('data-logo-chosen', '1');
                preview.appendChild(img);

                filename.textContent = file.name + ' - ' + Math.round(file.size / 1024) + ' KB';
                fileLabel.textContent = @json(__('Uploading...'));

                // No save button: the pick is the commit.
                form.submit();
            });
        })();
    </script>
    @endpush
</x-app-layout>