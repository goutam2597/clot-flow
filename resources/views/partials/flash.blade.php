{{--
    Session flash + validation failures, as toasts.

    The messages ride the same notification cards every other event in the app
    uses (App.toast), instead of a coloured banner shouting from the top of the
    page. Successes, warnings and notices leave on their own; validation
    failures stay until dismissed, because an error that times out is an error
    nobody read. Field-level errors still render beside their inputs — the
    toast is the summary, not a replacement.

    app.js is loaded before @stack('scripts') renders, so App.toast is always
    available here without a DOMContentLoaded dance.
--}}
@if (session('success') || session('error') || session('warning') || session('info') || $errors->any())
    @push('scripts')
    <script>
        (function () {
            var flash = [
                @if (session('success'))
                    { message: @json(session('success')), type: 'success' },
                @endif
                @if (session('error'))
                    { message: @json(session('error')), type: 'danger' },
                @endif
                @if (session('warning'))
                    { message: @json(session('warning')), type: 'warning' },
                @endif
                @if (session('info'))
                    { message: @json(session('info')), type: 'info' },
                @endif
                @if ($errors->any())
                    { message: @json($errors->count() > 1
                        ? $errors->first() . ' ' . __('(and :n more)', ['n' => $errors->count() - 1])
                        : $errors->first()), type: 'danger', title: @json(__('Please correct the following errors')) },
                @endif
            ];

            // The namespace is window.Meridian - "App" only exists as an alias
            // inside each static module's own closure.
            var toaster = window.Meridian && window.Meridian.toast;
            if (!toaster) { return; }

            flash.forEach(function (item) {
                toaster(item.message, item.type, item.title || undefined);
            });
        })();
    </script>
    @endpush
@endif
