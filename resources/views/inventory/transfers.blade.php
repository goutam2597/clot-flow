<x-app-layout>
    <x-slot:title>{{ __('Stock Transfers') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Stock Transfers')" :subtitle="__('Move stock between warehouses with a full audit trail.')" :back="route('inventory.index')">
        <x-slot:actions>
            @can('inventory.transfer')
                <x-ui.button icon="plus" data-modal-open="#create-transfer">{{ __('New Transfer') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Transfer') }}</th>
                        <th>{{ __('From → To') }}</th>
                        <th class="num">{{ __('Lines') }}</th>
                        <th>{{ __('By') }}</th>
                        <th>{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transfers as $transfer)
                        <tr>
                            <td>
                                <div class="fw-medium font-mono">{{ $transfer->transfer_number }}</div>
                                <div class="fs-xs text-muted">{{ \App\Support\Formatter::dateTime($transfer->transferred_at) }}</div>
                                @if ($transfer->notes)<div class="fs-xs">{{ $transfer->notes }}</div>@endif
                            </td>
                            <td>
                                <div class="fs-sm">{{ $transfer->fromWarehouse?->name ?? '—' }}</div>
                                <div class="fs-xs text-muted">→ {{ $transfer->toWarehouse?->name ?? '—' }}</div>
                            </td>
                            <td class="num financial">{{ \App\Support\Formatter::number($transfer->items_count, 0) }}</td>
                            <td>{{ $transfer->user?->name ?? '—' }}</td>
                            <td>
                                @if ($transfer->status === 'completed')
                                    <x-ui.badge type="success">{{ __('Completed') }}</x-ui.badge>
                                @elseif ($transfer->status === 'pending')
                                    <x-ui.badge type="warning">{{ __('Pending') }}</x-ui.badge>
                                @else
                                    <x-ui.badge type="default">{{ ucfirst($transfer->status) }}</x-ui.badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state icon="truck" :title="__('No transfers yet')" :text="__('Move stock between warehouses and every line will be recorded here.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {!! $transfers->links('partials.pagination') !!}
    @can('inventory.transfer')
        <x-ui.modal id="create-transfer" :title="__('New Stock Transfer')" size="lg">
            <form method="POST" action="{{ route('inventory.transfers.store') }}" data-prevent-double id="transfer-form">
                @csrf
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 1 1 200px;">
                        <x-ui.select name="from_warehouse_id" :label="__('From warehouse')" :options="$warehouses" required />
                    </div>
                    <div style="flex: 1 1 200px;">
                        <x-ui.select name="to_warehouse_id" :label="__('To warehouse')" :options="$warehouses" required />
                    </div>
                </div>
                <x-ui.input name="notes" :label="__('Notes')" :placeholder="__('Optional reference')" />
                <div class="form-group">
                    <label class="form-label">{{ __('Lines') }}<span class="required">*</span></label>
                    <div id="transfer-lines" class="flex gap-2" style="flex-direction: column;"></div>
                    <x-ui.button variant="outline" size="sm" icon="plus" id="transfer-add-line">{{ __('Add line') }}</x-ui.button>
                </div>
                <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                    <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" icon="check">{{ __('Complete Transfer') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        @push('scripts')
        <script>
        (function () {
            var products = @json($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values());
            var lines = document.getElementById('transfer-lines');
            var addBtn = document.getElementById('transfer-add-line');
            if (!lines || !addBtn) { return; }
            var idx = 0;
            function esc(s) { return String(s).replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

            /*
             * The product picker is built as the same custom combobox the rest
             * of the site uses. A native <select> here would be the one control
             * in this form still drawn by the operating system, and on a branch
             * with a few thousand products it would be unusable as well.
             *
             * Re-enhanced after each row is added, because the rows are
             * injected rather than rendered.
             */
            function productSelectHtml(name) {
                var options = products.map(function (p) {
                    return '<option value="' + p.id + '">' + esc(p.name) + '</option>';
                }).join('');

                return '<div class="select" data-select data-placeholder=""' +
                        ' data-empty-text="' + esc(@__('No matches')) + '" style="flex: 2 1 0;">' +
                        '<select class="select-native" name="' + name + '" tabindex="-1" aria-hidden="true"' +
                            ' data-select-native="true" required>' + options + '</select>' +
                        '<div class="select-shell">' +
                            '<button type="button" class="select-trigger" aria-haspopup="listbox"' +
                                ' aria-expanded="false" aria-label="' + esc(@__('Product')) + '">' +
                                '<span class="select-value is-placeholder">' +
                                    esc(@__('Select a product')) + '</span>' +
                                '<svg class="select-chevron" viewBox="0 0 24 24" fill="none"' +
                                    ' stroke="currentColor" stroke-width="2.5" stroke-linecap="round"' +
                                    ' stroke-linejoin="round" aria-hidden="true">' +
                                    '<polyline points="6 9 12 15 18 9"></polyline></svg>' +
                            '</button>' +
                        '</div>' +
                        '<div class="select-panel" data-select-panel hidden>' +
                            '<div class="select-search-row"><div class="select-search-wrap">' +
                                '<svg class="select-search-icon" viewBox="0 0 24 24" fill="none"' +
                                    ' stroke="currentColor" stroke-width="2" stroke-linecap="round"' +
                                    ' aria-hidden="true"><circle cx="11" cy="11" r="7"></circle>' +
                                    '<line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' +
                                '<input type="text" class="select-search" data-select-search' +
                                    ' autocomplete="off" spellcheck="false" role="combobox"' +
                                    ' aria-expanded="true" placeholder="' +
                                    esc(@__('Search...')) + '">' +
                            '</div></div>' +
                            '<ul class="select-list" data-select-list role="listbox" tabindex="-1"></ul>' +
                        '</div>' +
                    '</div>';
            }

            function addLine() {
                var row = document.createElement('div');
                row.className = 'flex gap-2';
                row.innerHTML =
                    productSelectHtml('items[' + idx + '][product_id]') +
                    '<input name="items[' + idx + '][quantity]" type="number" min="0.0001" step="0.0001" value="1" class="form-control" required style="flex: 1 1 0;">' +
                    '<button type="button" class="btn btn-danger-ghost btn-icon" aria-label="Remove line">&times;</button>';
                row.querySelector('button').addEventListener('click', function () { row.remove(); });
                lines.appendChild(row);
                idx++;

                // Injected rows sit outside the page-level enhance() pass.
                if (window.Meridian && window.Meridian.selects) {
                    window.Meridian.selects.init(row);
                }
            }
            addBtn.addEventListener('click', function (e) { e.preventDefault(); addLine(); });
            addLine();
        })();
        </script>
        @endpush
    @endcan

</x-app-layout>
