{{--
    Close-session form. Shared by the current-session and session detail pages.
    Expects: $session (CashRegisterSession), $stats (array from CashRegisterSessionController::sessionStats).
--}}
<div class="card">
    <div class="card-header">
        <h2 class="card-title">{{ __('Close session') }}</h2>
    </div>
    <div class="card-body">
        <div class="filter-bar" style="margin-bottom: var(--space-4);">
            <div>
                <div class="fs-sm text-muted">{{ __('Expected cash in drawer') }}</div>
                <div class="financial fw-semibold" id="expected-cash" data-expected="{{ $stats['expected_cash'] }}">
                    {{ \App\Support\Formatter::money($stats['expected_cash']) }}
                </div>
            </div>
            <div>
                <div class="fs-sm text-muted">{{ __('Counted closing balance') }}</div>
                <div class="financial fw-semibold" id="closing-preview">{{ \App\Support\Formatter::money($stats['expected_cash']) }}</div>
            </div>
            <span class="spacer"></span>
            <div>
                <div class="fs-sm text-muted">{{ __('Difference') }}</div>
                <div class="financial fw-semibold" id="difference-preview">{{ \App\Support\Formatter::money('0') }}</div>
            </div>
        </div>

        <form method="POST" action="{{ route('pos.sessions.close', $session) }}" data-prevent-double id="close-session-form">
            @csrf
            @method('PATCH')

            <div class="filter-bar" style="border: none; padding: 0; margin-bottom: var(--space-3); background: transparent;">
                <div style="min-width: 200px;">
                    <x-ui.input
                        name="closing_balance"
                        type="number"
                        :label="__('Counted cash')"
                        :value="$stats['expected_cash']"
                        min="0"
                        step="0.01"
                        id="closing_balance"
                        required
                    />
                </div>
                <div style="flex: 1 1 240px;">
                    <x-ui.input name="notes" :label="__('Closing notes')" :placeholder="__('Explain any difference')" />
                </div>
            </div>

            <div class="flex-center gap-2">
                <x-ui.button variant="danger" type="submit" icon="x" data-prevent-double>{{ __('Close Session') }}</x-ui.button>
                <span class="fs-sm text-muted">{{ __('Closing is permanent and records the cash difference on the session.') }}</span>
            </div>
        </form>
    </div>
</div>

@push('scripts')
    <script>
        (function () {
            'use strict';

            var expectedEl = document.getElementById('expected-cash');
            var input = document.getElementById('closing_balance');
            var closingPreview = document.getElementById('closing-preview');
            var diffPreview = document.getElementById('difference-preview');
            var form = document.getElementById('close-session-form');

            if (!expectedEl || !input || !closingPreview || !diffPreview) { return; }

            var expected = parseFloat(expectedEl.getAttribute('data-expected')) || 0;
            var symbol = expectedEl.textContent.trim().replace(/[\d.,\s-]/g, '') || '';
            var decimals = (expectedEl.textContent.trim().split(/[.,]/).pop() || '').length || 2;

            function render(value) {
                var text = value.toFixed(decimals);
                closingPreview.textContent = symbol ? symbol + text : text;
                diffPreview.textContent = (value < 0 ? '-' : '') + (symbol ? symbol : '') + Math.abs(value).toFixed(decimals);
                diffPreview.classList.toggle('text-danger', value < 0);
                diffPreview.classList.toggle('text-success', value === 0);
            }

            function update() {
                render((parseFloat(input.value) || 0) - expected);
            }

            input.addEventListener('input', update);
            update();

            if (form) {
                form.addEventListener('submit', function () {
                    // Allow app.js to re-arm the guard only on a fresh page load.
                    setTimeout(function () { delete form.dataset.submitted; }, 0);
                }, true);
            }
        })();
    </script>
@endpush
