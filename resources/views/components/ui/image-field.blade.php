@props([
    'name' => 'image',
    'label' => null,
    /** Current image URL, if the record already has one. */
    'value' => null,
    /** Set when re-submitting after a validation error, so the preview survives. */
    'hint' => null,
])

@php
    /*
     * Two ways to set a product image, because a shop has both needs:
     *
     *  - Upload a file, for a product the owner photographed themselves.
     *  - Paste a URL, for a supplier's or a manufacturer's catalogue image.
     *
     * The URL is kept in its own text field rather than folded into the upload
     * so editing one never silently discards the other, and so the existing
     * catalogue — which is entirely URL-backed — can be corrected by hand.
     *
     * The preview is rendered server-side from the stored value; picking a new
     * file swaps it via a tiny inline script, because instant feedback is what
     * makes someone confident they attached the right photograph.
     */
    $preview = $value;
@endphp

<div class="form-group image-field"
     data-image-field
     data-preview-src="{{ $preview }}"
     @if ($errors->has($name)) data-invalid="true" @endif>

    <span class="form-label">{{ $label ?? __('Product image') }}</span>

    <div class="image-field-row">
        <div class="image-field-preview">
            <img data-image-preview
                 src="{{ $preview }}"
                 alt=""
                 @if (! $preview) hidden @endif>

            <span class="image-field-placeholder" data-image-placeholder
                  @if ($preview) hidden @endif>
                <x-ui.icon name="image" :size="22" />
            </span>
        </div>

        <div class="image-field-controls">
            <label class="image-field-file btn btn-secondary btn-block">
                <x-ui.icon name="upload" :size="15" />
                <span data-image-file-label>{{ $preview ? __('Replace image') : __('Choose a file') }}</span>
                <input type="file"
                       name="{{ $name }}"
                       accept="image/jpeg,image/png,image/webp,image/gif"
                       data-image-input
                       class="visually-hidden">
            </label>

            <span class="form-hint" data-image-filename></span>

            <div class="image-field-url">
                <label class="form-label" for="{{ $name }}-url">{{ __('or paste an image URL') }}</label>
                <input type="url"
                       id="{{ $name }}-url"
                       name="image_url"
                       value="{{ old('image_url', is_string($preview) && preg_match('#^https?://#i', $preview) ? $preview : '') }}"
                       placeholder="https://…"
                       class="form-control"
                       data-image-url>
            </div>

            <label class="form-check image-field-clear" data-image-clear-wrap @if (! $preview) hidden @endif>
                <x-ui.checkbox name="remove_image" value="1" variant="row" data-image-clear />
                <span class="form-check-label fs-sm">{{ __('Remove this image') }}</span>
            </label>
        </div>
    </div>

    @if ($hint)
        <div class="form-hint">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="form-error">{{ $message }}</div>
    @enderror
    @error('image_url')
        <div class="form-error">{{ $message }}</div>
    @enderror
</div>

@once
<script>
    /*
     * Image field behaviour. Vanilla, and scoped with [data-image-field] so the
     * same markup can appear in a create modal and fifteen edit modals on one
     * page without any of them interfering.
     */
    (function () {
        function wire(root) {
            if (root.dataset.wired === 'true') { return; }
            root.dataset.wired = 'true';

            var input = root.querySelector('[data-image-input]');
            var preview = root.querySelector('[data-image-preview]');
            var placeholder = root.querySelector('[data-image-placeholder]');
            var filename = root.querySelector('[data-image-filename]');
            var fileLabel = root.querySelector('[data-image-file-label]');
            var urlField = root.querySelector('[data-image-url]');
            var clearBox = root.querySelector('[data-image-clear]');
            var clearWrap = root.querySelector('[data-image-clear-wrap]');
            var replacedText = fileLabel ? fileLabel.textContent : '';

            function show(src) {
                if (preview) {
                    preview.src = src;
                    preview.hidden = false;
                }
                if (placeholder) { placeholder.hidden = true; }
                if (clearWrap) { clearWrap.hidden = false; }
            }

            if (input) {
                input.addEventListener('change', function () {
                    var file = input.files && input.files[0];
                    if (!file) { return; }

                    show(URL.createObjectURL(file));

                    if (filename) {
                        filename.textContent = file.name + ' — ' +
                            Math.round(file.size / 1024) + ' KB';
                    }
                    if (fileLabel) { fileLabel.textContent = replacedText; }

                    // A freshly chosen file must win over a stale URL, or the
                    // server would store whichever arrived last.
                    if (urlField) { urlField.value = ''; }
                    if (clearBox) { clearBox.checked = false; }
                });
            }

            if (urlField) {
                urlField.addEventListener('input', function () {
                    var value = urlField.value.trim();

                    if (value === '') {
                        if (preview && !root.dataset.previewSrc) { preview.hidden = true; }
                        if (placeholder && !root.dataset.previewSrc) { placeholder.hidden = false; }
                        if (clearWrap && !root.dataset.previewSrc) { clearWrap.hidden = true; }
                        return;
                    }

                    show(value);
                    if (input) { input.value = ''; }
                    if (filename) { filename.textContent = ''; }
                });
            }

            if (clearBox) {
                clearBox.addEventListener('change', function () {
                    if (!clearBox.checked) { return; }

                    if (preview) { preview.hidden = true; preview.removeAttribute('src'); }
                    if (placeholder) { placeholder.hidden = false; }
                    if (input) { input.value = ''; }
                    if (urlField) { urlField.value = ''; }
                    if (filename) { filename.textContent = ''; }
                    if (fileLabel) { fileLabel.textContent = replacedText.replace('Replace', 'Choose'); }
                });
            }
        }

        document.querySelectorAll('[data-image-field]').forEach(wire);

        // Modals are injected into the DOM as they open, so a field added after
        // first paint needs wiring too.
        document.addEventListener('click', function (event) {
            if (!event.target.closest('[data-modal-open]')) { return; }
            window.setTimeout(function () {
                document.querySelectorAll('[data-image-field]:not([data-wired="true"])').forEach(wire);
            }, 0);
        });
    })();
</script>
@endonce