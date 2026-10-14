{{--
    The till, on the chrome-free shell.

    Not x-layouts.app: a till has no sidebar and no topbar. Everything the
    cashier needs is on this screen, and the product grid gets the whole
    viewport.
--}}
<x-layouts.till :title="$title">
    @php
        $money = fn ($value) => \App\Support\Formatter::money($value);
        $number = fn ($value, int $decimals = 2) => \App\Support\Formatter::number($value, $decimals);

        /*
         * Everything the terminal script needs, delivered as one JSON payload so
         * the client never has to re-derive pricing, tax or currency rules.
         */
        $posBootstrap = [
            'products' => array_values($products),
            'categories' => $categories,
            'customers' => $customers,
            'paymentMethods' => $paymentMethods->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'code' => $m->code,
                'type' => $m->type,
                'requires_reference' => (bool) $m->requires_reference,
            ])->values(),
            'searchUrl' => route('pos.search'),
            'customersUrl' => route('pos.customers'),
            'saleUrl' => route('pos.store'),
            'holdsUrl' => route('pos.holds.index'),
            'syncUrl' => route('sync.receive'),
            // Terminal context travels in the payload rather than being scraped
            // out of hidden inputs: the warehouse picker is only rendered when a
            // branch has more than one warehouse, so a `querySelector` for it
            // silently finds nothing on a single-warehouse install and the
            // first checkout throws instead of failing loudly here.
            'warehouseId' => $warehouseId,
            'cashRegisterSessionId' => $currentSession?->id,
            // Set by the pop-out button. A popup can only take the screen after
            // it has loaded, so the flag is read here and acted on by pos.js
            // once the document is ready.
            'enterFullscreen' => request()->boolean('fullscreen'),
            'csrf' => csrf_token(),
            'business' => [
                'name' => \App\Support\Formatter::setting('business_name', config('app.name', 'Meridian POS')),
                'branch' => $branch?->name,
                'cashier' => auth()->user()->name,
                'warehouse' => $warehouse?->name,
            ],
            'session' => $currentSession ? [
                'id' => $currentSession->id,
                'register' => $currentSession->cashRegister?->name,
                'openedBy' => $currentSession->user?->name,
                'total' => $money($sessionSales['total']),
                'count' => $number($sessionSales['count'], 0),
            ] : null,
            'currency' => [
                'symbol' => \App\Support\Formatter::currency()->symbol,
                'position' => \App\Support\Formatter::currency()->symbol_position,
                'decimals' => (int) \App\Support\Formatter::currency()->decimal_places,
                'thousand' => \App\Support\Formatter::currency()->thousand_separator,
                'decimal' => \App\Support\Formatter::currency()->decimal_separator,
            ],
            'labels' => [
                'outOfStock' => __('Out of stock'),
                'lowStock' => __('Only :qty left', ['qty' => ':qty']),
                'noMatch' => __('Nothing matches that search'),
                'noMatchHint' => __('No product matches what you typed in the local catalogue.'),
                'noCatalog' => __('No products on this till yet'),
                'noCatalogHint' => __('This terminal has no catalogue, so it cannot sell anything. Add products, then reload.'),
                'noCustomer' => __('No matching customer'),
                'walkIn' => __('Walk-in customer'),
                'emptyCart' => __('Cart is empty'),
                'emptyCartHint' => __('Scan a barcode or pick a product to begin.'),
                'confirmClear' => __('Clear the whole cart?'),
                'stockWarning' => __('Only :qty left in stock.', ['qty' => ':qty']),
                'paymentRequired' => __('Record at least one payment.'),
                'underpaid' => __('Payments are less than the total due.'),
                'referenceRequired' => __('A reference is required for :method payments.', ['method' => ':method']),
                'remove' => __('Remove this line'),
                'increase' => __('Increase quantity'),
                'decrease' => __('Decrease quantity'),
                'method' => __('Method'),
                'amount' => __('Amount'),
                'tendered' => __('Tendered'),
                'change' => __('Change'),
                'reference' => __('Reference'),
                'held' => __('Held'),
                'nothingOnHold' => __('Nothing is on hold'),
                'holdPrefix' => __('Hold —'),
                'heldSaved' => __('Sale parked on hold.'),
                'holdFailed' => __('The sale could not be held. Check the connection and try again.'),
                'resume' => __('Resume'),
                'discard' => __('Discard this held sale'),
                'discardNamed' => __('Discard :name', ['name' => ':name']),
                'itemCount' => __(':count item(s)', ['count' => ':count']),
                'search' => __('Search…'),
                'noMatch' => __('No matches'),
                'fullscreen' => __('Fullscreen (F11)'),
                'exitFullscreen' => __('Exit fullscreen'),
                'fullscreenUnavailable' => __('This browser would not go fullscreen. Try F11, or open the till in its own window.'),
                'popupBlocked' => __('The browser blocked the new till window. Allow pop-ups for this site, then try again.'),
                'quantityFor' => __('Quantity for :name', ['name' => ':name']),
                'discountFor' => __('Discount for :name', ['name' => ':name']),
                'discounted' => __('Discounted'),
                'aboveStock' => __('Above stock on hand'),
                'offlineQueued' => __('You are offline. The sale was queued on this device and will sync automatically.'),
                'networkError' => __('The server could not be reached. The sale was queued on this device.'),
                'syncing' => __('Syncing :count queued sales…', ['count' => ':count']),
                'synced' => __('Everything is saved on the server'),
                'pending' => __(':count sale(s) waiting to sync', ['count' => ':count']),
                'offline' => __('Offline — sales are being queued on this device'),
                'never' => __('never'),
                'items' => __(':count item(s)', ['count' => ':count']),
            ],
            'offline' => [
                'enabled' => (bool) \App\Support\Formatter::setting('pos_enable_offline_mode', false),
            ],
        ];
    @endphp

    @include('partials.flash')

    {{--
        THE TERMINAL

        A full-height shell with exactly two working columns: the product grid
        on the start side, the cart on the end side. Nothing else is permanent
        chrome except the header, the sync strip and the shortcut bar, because
        a screen full of panels is a screen where the total is harder to find
        than the product list.

        Everything that can interrupt a sale lives INSIDE the shell. An alert
        stacked above it would push the till below the fold and reintroduce the
        page scroll this layout exists to eliminate — the grid would simply
        give up the height instead, which is the right trade.
    --}}
    <div class="pos-shell">

        {{-- ---- Header: who is selling, from where, under which session ---- --}}
        <header class="pos-header">
            <span class="pos-header-mark" aria-hidden="true"><x-ui.icon name="store" :size="18" /></span>

            <div class="pos-header-titles">
                <span class="pos-header-title">{{ $posBootstrap['business']['name'] }}</span>
                {{-- Names the register AND the person who opened it. A manager who is
                         selling on a colleague's open drawer needs to know whose
                         opening float those takings are landing against. --}}
                <span class="pos-header-sub">
                    {{ $branch?->name ?? __('No branch') }}
                    @if ($currentSession)
                        · {{ $currentSession->cashRegister?->name ?? __('Register') }}
                        @if ($currentSession->user_id && $currentSession->user_id !== auth()->id())
                            · {{ __('opened by :name', ['name' => $currentSession->user?->name ?? __('someone')]) }}
                        @endif
                    @endif
                    · {{ auth()->user()->name }}
                </span>
            </div>

            <div class="pos-header-actions">
                {{-- Language, theme and account.

                     The till has no sidebar and no topbar, so these are the only
                     route off this screen: without them, ending a shift means
                     navigating into the admin and abandoning whatever basket was
                     open. --}}
                @include('partials.account-controls', ['compact' => true])

                {{-- Held sales. Counted in the label, because a parked
                         basket the cashier cannot see is a customer waiting. --}}
                    <x-ui.button variant="secondary" size="sm" type="button" id="pos-holds-open"
                                 icon="pause" title="{{ __('Held sales (F8)') }}">
                        <span id="pos-holds-count" data-holds-count hidden></span>
                        {{ __('Held') }} <kbd>F8</kbd>
                    </x-ui.button>

                    {{-- A till lives on one screen doing one job. This puts the
                         terminal on a dedicated fullscreen surface for a second
                         monitor.

                         There is deliberately NO fullscreen button beside it, and
                         the reference the till is built to says why: "F11 is
                         deliberately NOT handled here: the browser's native
                         fullscreen". Our version used to both render a button for
                         it AND preventDefault F11, which took the browser's own
                         key away from the browser and replaced it with a second
                         icon that looks like the one next to it. One control for
                         one capability. --}}
                    <x-ui.button variant="ghost" size="sm" type="button" id="pos-popout"
                                 icon="external" title="{{ __('Open the till in a new fullscreen window') }}" />

                    <x-ui.button variant="ghost" size="sm" icon="dashboard"
                             href="{{ route('dashboard') }}" target="_blank"
                             title="{{ __('Open the admin dashboard in a new tab') }}" />

                @if ($warehouses->count() > 1)
                    {{-- Choosing a warehouse reloads the terminal against it, so
                         this select navigates rather than submits a form. The
                         custom combobox drives that through data-select-submit
                         rather than an inline onchange, so a re-rendered select
                         behaves like this one. --}}
                    <form method="GET" action="{{ route('pos.index') }}" id="pos-warehouse-form">
                        <x-ui.select name="warehouse_id" size="sm"
                                     wrapperClass="pos-warehouse-picker"
                                     data-select-submit
                                     :value="$warehouseId"
                                     :options="$warehouses->pluck('name', 'id')"
                                     :aria-label="__('Warehouse')" />
                    </form>
                @endif

                {{-- Credit is an explicit, visible permission for this terminal,
                     not a hidden setting: it defaults to off on every load.
                     The switch variant, so it reads as on/off rather than as a
                     tick, and sized to the header row like everything beside
                     it. --}}
                <x-ui.checkbox variant="switch" id="pos-allow-credit"
                               wrapperClass="pos-toggle"
                               :label="__('Credit allowed')" />

                {{-- Shift takings. A readout, not a control, but it sits between
                     two controls in this row — at badge height it read as a
                     stray chip dropped into the middle of a button row.
                     Matching the control height and border shape makes the row
                     one line. --}}
                @if ($currentSession)
                    <span class="pos-session-total"
                          title="{{ __('Takings this shift · :count sales', ['count' => $number($sessionSales['count'], 0)]) }}">
                        <span class="pos-session-total-value">{{ $money($sessionSales['total']) }}</span>
                        <span class="pos-session-total-label">{{ __('shift') }}</span>
                    </span>
                @endif
            </div>
        </header>

        {{-- ---- Blocking misconfiguration, stated before any sale is rung up:
                 a cashier must not start a basket that cannot be saved. Only
                 genuinely unsaveable states earn this strip; a session opened
                 by a colleague is normal trading, not a fault, and is reported
                 quietly in the header instead. --}}
        @if (! $warehouse || ! $currentSession)
            <div class="pos-alerts">
                @if (! $currentSession)
                    <x-ui.alert type="warning" :title="__('No register session is open')">
                        {{ __('Cash sales are normally tied to a drawer session. Sales will be recorded without one until you open a session.') }}
                        &nbsp;<a href="{{ route('pos.sessions.create') }}">{{ __('Open a session') }}</a>
                    </x-ui.alert>
                @endif

                @if (! $warehouse)
                    <x-ui.alert type="danger" :title="__('No warehouse available')">
                        {{ __('Your branch has no active warehouse, so stock cannot be deducted. Contact an administrator.') }}
                    </x-ui.alert>
                @endif
            </div>
        @endif

        {{-- ---- Sync strip: permanent, because "is this till trusted?" must
                 be answerable without hunting for a toast that already faded. --}}
        <div class="pos-sync" id="pos-sync" data-state="synced">
            <span id="pos-sync-icon" aria-hidden="true"></span>
            <span class="pos-sync-message" id="pos-sync-message" role="status" aria-live="polite">
                {{ __('Everything is saved on the server') }}
            </span>
            <span class="pos-sync-time" id="pos-sync-time"></span>
            <button type="button" class="pos-sync-action" id="pos-sync-now">
                {{ __('Sync now') }}
            </button>
        </div>

        {{-- ---- The two working columns ---- --}}
        <div class="pos-body">

            {{-- ============ Products ============ --}}
            <section class="pos-catalog" aria-label="{{ __('Products') }}">

                <form class="pos-search" id="pos-search-form" role="search" onsubmit="return false;">
                    <span class="pos-search-icon"><x-ui.icon name="search" :size="16" /></span>
                    <input type="text" id="pos-search" class="form-control"
                           autocomplete="off" spellcheck="false"
                           placeholder="{{ __('Scan a barcode or type a name, SKU or code, then press Enter') }}"
                           aria-label="{{ __('Product search') }}">
                </form>

                {{-- Category chips. Rendered server-side so the till has a usable
                     grid before any script runs. --}}
                @if ($categories->isNotEmpty())
                    <div class="pos-filters scrollbar-thin" id="pos-filters" role="group"
                         aria-label="{{ __('Filter by category') }}">
                        <button type="button" class="pos-chip is-active" data-category="">
                            {{ __('All') }}
                            <span class="pos-chip-count">{{ $number(count($products), 0) }}</span>
                        </button>
                        @foreach ($categories as $category)
                            <button type="button" class="pos-chip" data-category="{{ $category['id'] }}">
                                {{ $category['name'] }}
                                <span class="pos-chip-count">{{ $number($category['count'], 0) }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="pos-grid-wrap scrollbar-thin">
                    <ul class="pos-grid" id="pos-product-grid" aria-live="polite">
                        @forelse ($products as $item)
                            @php $out = $item['track_stock'] && ! $item['allow_negative_stock'] && (float) $item['available'] <= 0; @endphp
                            <li>
                                <button type="button"
                                        class="pos-product @if ($out) is-out @endif"
                                        data-product-id="{{ $item['id'] }}"
                                        data-category="{{ $item['category_id'] ?? '' }}"
                                        title="{{ $item['name'] }}">
                                    <span class="pos-product-media">
                                        @if ($item['image_url'])
                                            <img src="{{ $item['image_url'] }}" alt="" loading="lazy">
                                        @else
                                            <span class="pos-product-media-empty">
                                                <x-ui.icon name="box" :size="22" />
                                            </span>
                                        @endif

                                        @if ($out)
                                            <span class="pos-product-flag">
                                                <x-ui.badge type="danger">{{ __('Out of stock') }}</x-ui.badge>
                                            </span>
                                        @endif
                                    </span>

                                    <span class="pos-product-body">
                                        <span class="pos-product-name">{{ $item['name'] }}</span>
                                        <span class="pos-product-foot">
                                            <span class="pos-product-price">{{ $money($item['price']) }}</span>
                                            @if ($item['track_stock'])
                                                <span class="pos-product-stock @if ($out) is-out @elseif ((float) $item['available'] <= 5) is-low @endif"
                                                      title="{{ __('Available to sell') }}">
                                                    {{ $number($item['available'], 0) }}
                                                </span>
                                            @endif
                                        </span>
                                        {{-- Always rendered, even when empty: the row is what keeps every tile
                                             in the grid the same height. --}}
                                        <span class="pos-product-sku">{{ $item['sku'] ?: ($item['barcode'] ?? '') }}</span>
                                    </span>
                                </button>
                            </li>
                        @empty
                            <li class="pos-grid-empty">
                                <x-ui.empty-state icon="box"
                                                 :title="__('No active products')"
                                                 :text="__('Add active products to the catalogue to start selling.')" />
                            </li>
                        @endforelse
                    </ul>

                    {{-- Shown by pos.js while the terminal has no catalogue. --}}
                    <div class="pos-empty" id="pos-grid-empty" hidden>
                        <span class="pos-empty-icon"><x-ui.icon name="box" :size="24" /></span>
                        <span class="pos-empty-title" id="pos-grid-empty-title"></span>
                        <span class="pos-empty-text" id="pos-grid-empty-text"></span>
                    </div>
                </div>
            </section>

            {{-- ============ Cart ============ --}}
            <aside class="pos-cart" aria-label="{{ __('Current sale') }}">

                <div class="pos-cart-head">
                    <button type="button" class="pos-customer" id="pos-customer-open">
                        <x-ui.icon name="user" :size="15" />
                        <span class="pos-customer-name" id="pos-customer-name">{{ __('Walk-in customer') }}</span>
                        <kbd>F4</kbd>
                    </button>

                    <x-ui.button variant="secondary" size="sm" type="button" id="pos-hold"
                                 title="{{ __('Park this sale (F8)') }}">
                        {{ __('Hold') }} <kbd>F8</kbd>
                    </x-ui.button>

                    <x-ui.button variant="ghost" size="sm" type="button" id="pos-clear-cart"
                                 title="{{ __('Clear the cart') }}">
                        {{ __('Clear') }}
                    </x-ui.button>
                </div>

                <ul class="pos-cart-lines scrollbar-thin" id="pos-cart-lines"></ul>

                {{-- Empty state lives in the panel, not beside it, so the
                     column's height never changes between sales. --}}
                <div class="pos-empty" id="pos-cart-empty">
                    <span class="pos-empty-icon"><x-ui.icon name="cart" :size="22" /></span>
                    <span class="pos-empty-title">{{ __('Cart is empty') }}</span>
                    <span class="pos-empty-text">{{ __('Scan a barcode or pick a product to begin.') }}</span>
                </div>

                <footer class="pos-totals">
                    <div class="pos-money">
                        <span class="pos-money-label">{{ __('Subtotal') }}</span>
                        <span class="pos-money-value" id="pos-subtotal">{{ $money(0) }}</span>
                    </div>

                    <div class="pos-money is-muted" id="pos-discount-row">
                        <span class="pos-money-label">{{ __('Discount') }}</span>
                        <span class="pos-money-value" id="pos-discount">−{{ $money(0) }}</span>
                    </div>

                    <div class="pos-money">
                        <span class="pos-money-label">{{ __('Tax') }}</span>
                        <span class="pos-money-value" id="pos-tax">{{ $money(0) }}</span>
                    </div>

                    {{-- THE TOTAL. The largest number in the entire till. --}}
                    <div class="pos-total">
                        <div class="pos-total-meta">
                            <span class="pos-total-label">{{ __('Total') }}</span>
                            <span class="pos-total-count" id="pos-line-count">0</span>
                        </div>
                        <span class="pos-total-value" id="pos-grand-total">{{ $money(0) }}</span>
                    </div>

                    {{-- Both actions are `lg` and so share one height. A secondary button
                         that is a step shorter than the primary beside it is
                         the single most common way a form row looks
                         unfinished, and it is invisible until two buttons sit
                         next to each other. --}}
                    <div class="pos-cart-actions">
                        <x-ui.button variant="secondary" size="lg" type="button" id="pos-open-payment"
                                     icon="percent" title="{{ __('Discount the whole sale') }}">
                            {{ __('Discount') }}
                        </x-ui.button>

                        {{-- Charge never touches the network directly: it opens
                             the tender sheet. Submission goes through pos.js,
                             which queues offline if it must. --}}
                        <x-ui.button variant="primary" size="lg" type="button" id="pos-charge" class="pos-charge">
                            {{ __('Charge') }} <kbd>F9</kbd>
                        </x-ui.button>
                    </div>
                </footer>
            </aside>
        </div>

        {{-- ---- Shortcut bar: visible bindings only. A key a cashier cannot
                 see is a key a cashier will not press. ---- --}}
        <footer class="pos-shortcuts">
            <span class="pos-shortcut"><kbd>F2</kbd> {{ __('Search') }}</span>
            <span class="pos-shortcut"><kbd>F4</kbd> {{ __('Customer') }}</span>
            <span class="pos-shortcut"><kbd>F8</kbd> {{ __('Hold / held') }}</span>
            <span class="pos-shortcut"><kbd>F11</kbd> {{ __('Fullscreen') }}</span>
            <span class="pos-shortcut"><kbd>F9</kbd> {{ __('Charge') }}</span>
            <span class="pos-shortcut"><kbd>F10</kbd> {{ __('Focus total') }}</span>
            <span class="pos-shortcut"><kbd>↑</kbd><kbd>↓</kbd> {{ __('Select line') }}</span>
            <span class="pos-shortcut"><kbd>+</kbd><kbd>−</kbd> {{ __('Change quantity') }}</span>
            <span class="pos-shortcut"><kbd>Del</kbd> {{ __('Remove line') }}</span>
            <span class="pos-shortcut"><kbd>Esc</kbd> {{ __('Clear search / cart') }}</span>
        </footer>
    </div>

    {{-- ======================= Tender sheet ======================= --}}
    {{-- A modal, not a permanent panel. The total is what the cashier watches;
         the tender breakdown is what they look at once, when they need it. --}}
    <div class="pos-modal" id="pos-payment-modal" hidden>
        <div class="pos-modal-backdrop" data-pos-close></div>

        <div class="pos-modal-panel is-wide" role="dialog" aria-modal="true"
             aria-labelledby="pos-payment-title">
            <header class="pos-modal-head">
                <h2 class="pos-modal-title" id="pos-payment-title">{{ __('Take payment') }}</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-pos-close
                        aria-label="{{ __('Close') }}">&times;</button>
            </header>

            <div class="pos-modal-body">
                <div class="pos-payment-grid">
                    {{-- Left: the amounts. --}}
                    <div class="pos-payment-main">
                        <div class="pos-tender-due">
                            <span class="pos-total-label">{{ __('Amount due') }}</span>
                            <span class="pos-total-value" id="pos-payment-due">{{ $money(0) }}</span>
                        </div>

                        <div class="pos-payment-rows" id="pos-payment-rows"></div>

                        <button type="button" class="pos-link-btn" id="pos-add-payment">
                            + {{ __('Add another method') }}
                        </button>

                        <div class="pos-payment-balance">
                            <span class="pos-money-label">{{ __('Balance remaining') }}</span>
                            <span class="pos-money-value" id="pos-balance">{{ $money(0) }}</span>
                        </div>

                        <div class="pos-payment-balance is-credit">
                            <span class="pos-money-label">{{ __('Change due') }}</span>
                            <span class="pos-money-value" id="pos-change">{{ $money(0) }}</span>
                        </div>

                        <div class="pos-form-group">
                            <label class="form-label" for="pos-note">{{ __('Note on this sale') }}</label>
                            <textarea class="form-control" id="pos-note" name="notes" rows="2" maxlength="1000"
                                      placeholder="{{ __('Optional') }}"></textarea>
                        </div>
                    </div>

                    {{-- Right: quick tender. Cash in, exact change out. --}}
                    <div class="pos-payment-side">
                        <span class="pos-total-label">{{ __('Quick cash') }}</span>
                        <div class="pos-cash-grid" id="pos-cash-grid"></div>
                    </div>
                </div>

                <p class="pos-payment-error" id="pos-payment-error" hidden role="alert"></p>

                <p class="pos-payment-hint">
                    {{ __('Amounts are recalculated on the server when the sale is saved.') }}
                </p>
            </div>

            <footer class="pos-modal-foot">
                <x-ui.button variant="ghost" type="button" data-pos-close>{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="primary" size="lg" type="button" id="pos-complete">
                    {{ __('Complete sale') }} <kbd>Enter</kbd>
                </x-ui.button>
            </footer>
        </div>
    </div>

    {{-- ======================= Held sales ======================= --}}
    <div class="pos-modal" id="pos-holds-modal" hidden>
        <div class="pos-modal-backdrop" data-pos-close></div>

        <div class="pos-modal-panel is-wide" role="dialog" aria-modal="true"
             aria-labelledby="pos-holds-title">
            <header class="pos-modal-head">
                <h2 class="pos-modal-title" id="pos-holds-title">{{ __('Held sales') }}</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-pos-close
                        aria-label="{{ __('Close') }}">&times;</button>
            </header>

            <div class="pos-modal-body">
                <ul class="pos-hold-list" id="pos-holds-list"></ul>

                <div class="pos-empty" id="pos-holds-empty" hidden>
                    <span class="pos-empty-icon"><x-ui.icon name="pause" :size="22" /></span>
                    <span class="pos-empty-title">{{ __('Nothing is on hold') }}</span>
                    <span class="pos-empty-text">
                        {{ __('Park a sale with F8 when a customer needs to step away. It is saved against this branch, so it survives a reload or a restart.') }}
                    </span>
                </div>

                <p class="pos-payment-error" id="pos-holds-error" hidden role="alert"></p>
            </div>

            <footer class="pos-modal-foot">
                <x-ui.button variant="ghost" type="button" data-pos-close>{{ __('Close') }}</x-ui.button>
            </footer>
        </div>
    </div>

    {{-- ======================= Customer picker ======================= --}}
    <div class="pos-modal" id="pos-customer-modal" hidden>
        <div class="pos-modal-backdrop" data-pos-close></div>

        <div class="pos-modal-panel" role="dialog" aria-modal="true"
             aria-labelledby="pos-customer-title">
            <header class="pos-modal-head">
                <h2 class="pos-modal-title" id="pos-customer-title">{{ __('Attach a customer') }}</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-pos-close
                        aria-label="{{ __('Close') }}">&times;</button>
            </header>

            <div class="pos-modal-body">
                <div class="pos-search">
                    <span class="pos-search-icon"><x-ui.icon name="search" :size="16" /></span>
                    <input type="text" id="pos-customer-search" class="form-control"
                           autocomplete="off" role="combobox" aria-expanded="false"
                           aria-controls="pos-customer-results"
                           placeholder="{{ __('Search by name, phone or code') }}">
                </div>

                <div class="pos-result-list" id="pos-customer-results" role="listbox"></div>
            </div>

            <footer class="pos-modal-foot">
                <x-ui.button variant="ghost" type="button" id="pos-customer-clear">
                    {{ __('Clear customer') }}
                </x-ui.button>
                <x-ui.button variant="secondary" type="button" data-pos-close>{{ __('Done') }}</x-ui.button>
            </footer>
        </div>
    </div>

    {{-- ======================= Discount ======================= --}}
    <div class="pos-modal" id="pos-discount-modal" hidden>
        <div class="pos-modal-backdrop" data-pos-close></div>

        <div class="pos-modal-panel" role="dialog" aria-modal="true"
             aria-labelledby="pos-discount-title">
            <header class="pos-modal-head">
                <h2 class="pos-modal-title" id="pos-discount-title">{{ __('Discount the whole sale') }}</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-pos-close
                        aria-label="{{ __('Close') }}">&times;</button>
            </header>

            <div class="pos-modal-body">
                <div class="pos-form-group">
                    <label class="form-label" for="pos-discount-input">{{ __('Discount amount') }}</label>
                    <input type="number" id="pos-discount-input" class="form-control"
                           step="0.01" min="0" inputmode="decimal">
                </div>

                <p class="pos-payment-hint">
                    {{ __('A discount reduces what the goods are worth, so tax is charged on the discounted amount. It can never make the sale payable.') }}
                </p>
            </div>

            <footer class="pos-modal-foot">
                <x-ui.button variant="ghost" type="button" data-pos-close>{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="primary" type="button" id="pos-discount-apply">{{ __('Apply') }}</x-ui.button>
            </footer>
        </div>
    </div>

    {{-- ======================= Receipt ======================= --}}
    <x-ui.modal id="pos-receipt-modal" :title="__('Sale completed')" size="sm">
        <div class="text-center">
            <div class="empty-state-icon" style="margin: 0 auto;">
                <x-ui.icon name="check-circle" :size="32" />
            </div>
            <div class="empty-state-title mt-3" id="pos-receipt-title"></div>
            <div class="empty-state-text" id="pos-receipt-detail"></div>
        </div>

        <div class="flex gap-3 mt-4">
            <x-ui.button variant="primary" icon="print" id="pos-receipt-open" style="flex: 1 1 auto;">
                {{ __('Open receipt') }}
            </x-ui.button>
            <x-ui.button variant="outline" icon="plus" id="pos-new-sale" style="flex: 1 1 auto;">
                {{ __('New sale') }}
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{--
        Pushed from inside the layout: the layout renders its @stack when the
        component is resolved, so a @push written after the closing tag would
        arrive too late and the terminal would load with no JavaScript at all.
    --}}
    @push('scripts')
        <script id="pos-bootstrap" type="application/json">@json($posBootstrap)</script>
        {{-- Versioned: a stale pos.js is the single most confusing failure this page
             can have, because the markup updates but the behaviour does not. --}}
        <script src="{{ \App\Support\Asset::url('js/offline.js') }}"></script>
        <script src="{{ \App\Support\Asset::url('js/pos.js') }}"></script>

        {{--
            The service worker only makes the terminal *loadable* while offline.
            Transaction durability comes from offline.js and the server's
            idempotency keys, never from page caching.

            Deliberately NOT versioned: a worker's scope is derived from its
            own URL, so a query string here would register it under a different
            scope every deploy and orphan the one already installed.
        --}}
        <script>
            if ('serviceWorker' in navigator && window.location.protocol !== 'file:') {
                window.addEventListener('load', function () {
                    navigator.serviceWorker.register('{{ asset('sw.js') }}').catch(function () {
                        // Offline page caching is an enhancement; the terminal
                        // works without it, so a failure is not worth reporting.
                    });
                });
            }
        </script>
    @endpush
</x-layouts.till>