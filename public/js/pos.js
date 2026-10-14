/* ==========================================================================
   Meridian POS — Point of Sale terminal

   Vanilla JS. The browser never decides a price, a discount, a tax or a
   total: every figure here is a live preview, and the server recalculates
   everything from product records inside SaleService before saving.

   WHEN THE CONNECTION DROPS and offline mode is enabled, the sale is written
   to IndexedDB with a stable idempotency key and pushed later by offline.js.

   THE ONE INVARIANT THIS FILE ENFORCES: the Charge button never talks to the
   network directly. It opens the tender sheet. Submission goes through
   captureSale(), which writes to IndexedDB and returns. The page is therefore
   identical whether the connection is up, down, or flapping — which is the
   whole point of an offline till.
   ========================================================================== */
(function () {
    'use strict';

    var bootEl = document.getElementById('pos-bootstrap');
    if (!bootEl) { return; }

    var boot = JSON.parse(bootEl.textContent);
    var App = window.Meridian || (window.Meridian = {});

    /* ---------------- Decimal helpers (integer minor units, never floats) - */

    function toScaled(value, decimals) {
        var n = parseFloat(value);
        if (isNaN(n)) { return 0; }
        return Math.round(n * Math.pow(10, decimals));
    }

    function fromScaled(value, decimals) {
        return (value / Math.pow(10, decimals)).toFixed(decimals);
    }

    function money(value) {
        var d = boot.currency.decimals;
        var v = toScaled(value || 0, d);
        var negative = v < 0;
        var abs = Math.abs(v).toString();

        // Zero-pad to at least one whole digit, otherwise a value below the
        // currency's precision (7 cents) slices into "$.7" instead of "$0.07".
        while (abs.length <= d) { abs = '0' + abs; }

        // Split off the decimals BEFORE grouping the thousands. Grouping first
        // puts a separator inside the fraction, and slicing the grouped string
        // then renders ten units as "$1,0.00" -- a wrong figure on the screen
        // a cashier is about to take money against.
        var whole = abs.slice(0, abs.length - d);
        var frac = abs.slice(abs.length - d);

        if (boot.currency.thousand && whole.length > 3) {
            whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, boot.currency.thousand);
        }

        var body = (d > 0 ? whole + (boot.currency.decimal || '.') + frac : whole);

        return (negative ? '-' : '') + (boot.currency.position === 'after'
            ? body + boot.currency.symbol
            : boot.currency.symbol + body);
    }

    function qty(value) {
        var v = parseFloat(value);
        if (isNaN(v)) { return '0'; }
        return (Math.round(v * 10000) / 10000).toFixed(2).replace(/\.00$/, '');
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
    }

    /** Substitute `:key` placeholders in a translated string. */
    function fill(template, map) {
        return String(template).replace(/:([a-zA-Z_]+)/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : match;
        });
    }

    /* ---------------- State ------------------------------------------------ */

    var state = {
        lines: [],            // { uid, product, quantity, discount }
        payments: [],         // { method_id, amount, tendered, reference }
        customer: null,
        orderDiscount: 0,     // minor units
        shipping: 0,          // minor units
        selectedIndex: -1,    // -1 = "no line selected"
        categoryId: '',
        query: '',
        idempotencyKey: null,
        allowCredit: false,
        holds: []              // parked carts, newest first
    };

    var catalog = {};
    var lineSeq = 0;
    (boot.products || []).forEach(function (p) { catalog[p.id] = p; });

    /* ---------------- Element handles ------------------------------------- */

    function byId(id) { return document.getElementById(id); }

    var el = {
        search: byId('pos-search'),
        grid: byId('pos-product-grid'),
        gridEmpty: byId('pos-grid-empty'),
        gridEmptyTitle: byId('pos-grid-empty-title'),
        gridEmptyText: byId('pos-grid-empty-text'),
        filters: byId('pos-filters'),

        lines: byId('pos-cart-lines'),
        cartEmpty: byId('pos-cart-empty'),
        lineCount: byId('pos-line-count'),
        subtotal: byId('pos-subtotal'),
        discountRow: byId('pos-discount-row'),
        discount: byId('pos-discount'),
        tax: byId('pos-tax'),
        grand: byId('pos-grand-total'),

        clearCart: byId('pos-clear-cart'),
        charge: byId('pos-charge'),
        openPayment: byId('pos-open-payment'),

        customerOpen: byId('pos-customer-open'),
        customerName: byId('pos-customer-name'),
        customerSearch: byId('pos-customer-search'),
        customerResults: byId('pos-customer-results'),
        customerClear: byId('pos-customer-clear'),

        holdsOpen: byId('pos-holds-open'),
        hold: byId('pos-hold'),
        holdsCount: document.querySelector('[data-holds-count]'),
        holdsList: byId('pos-holds-list'),
        holdsEmpty: byId('pos-holds-empty'),
        holdsError: byId('pos-holds-error'),
        holdsModal: byId('pos-holds-modal'),

        fullscreen: byId('pos-fullscreen'),
        popout: byId('pos-popout'),

        // Carried on the page rather than scraped from the DOM: the warehouse
        // picker is only rendered when a branch has more than one warehouse.
        warehouseId: boot.warehouseId || null,
        sessionId: boot.cashRegisterSessionId || null,

        paymentModal: byId('pos-payment-modal'),
        paymentDue: byId('pos-payment-due'),
        paymentRows: byId('pos-payment-rows'),
        paymentError: byId('pos-payment-error'),
        addPayment: byId('pos-add-payment'),
        cashGrid: byId('pos-cash-grid'),
        balance: byId('pos-balance'),
        change: byId('pos-change'),
        complete: byId('pos-complete'),
        note: byId('pos-note'),

        discountModal: byId('pos-discount-modal'),
        discountInput: byId('pos-discount-input'),
        discountApply: byId('pos-discount-apply'),

        customerModal: byId('pos-customer-modal'),

        syncStrip: byId('pos-sync'),
        syncMessage: byId('pos-sync-message'),
        syncTime: byId('pos-sync-time'),
        syncNow: byId('pos-sync-now'),
        creditToggle: document.querySelector('.pos-toggle'),
        allowCredit: byId('pos-allow-credit'),

        receiptModal: byId('pos-receipt-modal'),
        receiptTitle: byId('pos-receipt-title'),
        receiptDetail: byId('pos-receipt-detail'),
        receiptOpen: byId('pos-receipt-open'),
        newSale: byId('pos-new-sale')
    };

    var receiptUrl = null;

    /* ---------------- Totals ------------------------------------------------ */

    /*
     * Discount is applied BEFORE tax, and that ordering is not cosmetic: tax on
     * the pre-discount amount would overstate the liability and disagree with
     * what SaleService posts to the ledger. The server is authoritative; this
     * exists so the preview matches it rather than to be trusted.
     */
    function computeTotals() {
        var qtyScale = 10000;               // 4dp quantity precision
        var subtotal = 0;                   // minor units
        var tax = 0;
        var lineDiscount = 0;

        state.lines.forEach(function (line) {
            var unit = toScaled(line.product.price, 2);
            var quantity = toScaled(line.quantity, 2);
            var gross = Math.round((unit * quantity) / qtyScale);
            var discount = Math.min(toScaled(line.discount || 0, 2), gross);
            var net = gross - discount;

            subtotal += net;
            lineDiscount += discount;

            var rate = parseFloat(line.product.tax_rate || 0);
            tax += Math.round((net * rate) / 100);
        });

        // An order discount can never exceed what is still owed.
        var orderDiscount = Math.min(state.orderDiscount, Math.max(subtotal, 0));
        subtotal -= orderDiscount;

        var shipping = state.shipping;
        var grand = subtotal + tax + shipping;

        var paid = state.payments.reduce(function (sum, p) {
            return sum + toScaled(p.amount || 0, 2);
        }, 0);

        return {
            subtotal: subtotal,
            tax: tax,
            discount: lineDiscount + orderDiscount,
            orderDiscount: orderDiscount,
            shipping: shipping,
            grand: grand,
            paid: paid,
            balance: grand - paid,
            change: Math.max(0, paid - grand),
            lineCount: state.lines.length,
            unitCount: state.lines.reduce(function (n, l) { return n + (parseFloat(l.quantity) || 0); }, 0)
        };
    }

    function renderTotals() {
        var t = computeTotals();

        el.subtotal.textContent = money(fromScaled(t.subtotal, 2));
        el.tax.textContent = money(fromScaled(t.tax, 2));
        el.grand.textContent = money(fromScaled(t.grand, 2));

        var hasDiscount = t.discount > 0;
        el.discountRow.hidden = !hasDiscount;
        if (hasDiscount) {
            el.discount.textContent = '−' + money(fromScaled(t.discount, 2));
        }

        el.lineCount.textContent = fill(boot.labels.items, { count: t.lineCount });
        el.cartEmpty.hidden = t.lineCount > 0;

        return t;
    }

    /* ---------------- Product rendering ------------------------------------- */

    function stockTone(product) {
        if (!product.track_stock) { return ''; }

        var available = parseFloat(product.available || 0);
        var out = available <= 0 && !product.allow_negative_stock;

        if (out) { return 'is-out'; }
        return available <= 5 ? 'is-low' : '';
    }

    function isOutOfStock(product) {
        return product.track_stock && !product.allow_negative_stock && parseFloat(product.available || 0) <= 0;
    }

    function productCardHtml(product) {
        var out = isOutOfStock(product);
        var code = product.sku || product.barcode || '';
        var flash = product.__flash ? ' is-flash' : '';

        return '' +
            '<li>' +
                '<button type="button" class="pos-product' + (out ? ' is-out' : '') + flash + '"' +
                    ' data-product-id="' + product.id + '"' +
                    ' data-category="' + (product.category_id || '') + '"' +
                    ' title="' + escapeHtml(product.name) + '">' +

                    '<span class="pos-product-media">' +
                        (product.image_url
                            ? '<img src="' + escapeHtml(product.image_url) + '" alt="" loading="lazy">'
                            : '<span class="pos-product-media-empty">' + icon('box', 22) + '</span>') +
                        (out
                            ? '<span class="pos-product-flag"><span class="badge badge-danger">' +
                                escapeHtml(boot.labels.outOfStock) + '</span></span>'
                            : '') +
                    '</span>' +

                    '<span class="pos-product-body">' +
                        '<span class="pos-product-name">' + escapeHtml(product.name) + '</span>' +
                        '<span class="pos-product-foot">' +
                            '<span class="pos-product-price">' + escapeHtml(money(product.price)) + '</span>' +
                            (product.track_stock
                                ? '<span class="pos-product-stock ' + stockTone(product) + '" title="' +
                                    escapeHtml(boot.labels.outOfStock) + '">' +
                                    escapeHtml(qty(product.available)) + '</span>'
                                : '') +
                        '</span>' +
                        (code ? '<span class="pos-product-sku">' + escapeHtml(code) + '</span>' : '<span class="pos-product-sku"></span>') +
                    '</span>' +
                '</button>' +
            '</li>';
    }

    /** Inline SVG so the client-rendered grid matches the server-rendered one. */
    function icon(name, size) {
        var paths = {
            box: '<path d="M21 8v8a2 2 0 0 1-1 1.73l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.73l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
            search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            cart: '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>'
        };

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size +
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" ' +
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            (paths[name] || '') + '</svg>';
    }

    function renderGrid(products) {
        if (!products || products.length === 0) {
            el.grid.innerHTML = '';
            el.grid.hidden = true;
            el.gridEmpty.hidden = false;

            var searching = state.query !== '';
            el.gridEmptyTitle.textContent = searching ? boot.labels.noMatch : boot.labels.noCatalog;
            el.gridEmptyText.textContent = searching ? boot.labels.noMatchHint : boot.labels.noCatalogHint;
            return;
        }

        el.grid.hidden = false;
        el.gridEmpty.hidden = true;
        el.grid.innerHTML = products.map(productCardHtml).join('');
    }

    /** Products matching the current query AND the active category chip. */
    function visibleProducts(products) {
        var term = state.query.toLowerCase();
        var category = state.categoryId;

        return (products || []).filter(function (p) {
            if (category !== '' && String(p.category_id || '') !== String(category)) { return false; }
            if (term === '') { return true; }

            return (p.name || '').toLowerCase().indexOf(term) !== -1
                || (p.sku || '').toLowerCase().indexOf(term) !== -1
                || (p.barcode || '').toLowerCase().indexOf(term) !== -1;
        });
    }

    /* ---------------- Cart rendering ---------------------------------------- */

    function lineHtml(line, index) {
        var priced = priceLine(line);
        var low = line.product.track_stock
            && !line.product.allow_negative_stock
            && (parseFloat(line.quantity) || 0) > parseFloat(line.product.available || 0);
        var code = line.product.sku || line.product.barcode || '—';

        // "SKU · price each · unit" — the trailing "· discounted" only when it
        // is true, so the line never carries a stale qualifier.
        var meta = [code, money(line.product.price) + ' ' + (line.product.unit || '')];
        if (priced.discount > 0) { meta.push(boot.labels.discounted); }

        return '' +
            '<li class="pos-line' + (index === state.selectedIndex ? ' is-selected' : '') + '"' +
                ' data-index="' + index + '" data-uid="' + line.uid + '">' +

                '<div class="pos-line-top">' +
                    '<span class="pos-line-index">' + (index + 1) + '</span>' +
                    (line.product.image_url
                        ? '<img class="pos-line-thumb" src="' + escapeHtml(line.product.image_url) + '" alt="">'
                        : '') +
                    '<span class="pos-line-info">' +
                        '<span class="pos-line-name">' + escapeHtml(line.product.name) + '</span>' +
                        '<span class="pos-line-meta">' + escapeHtml(meta.join(' · ')) + '</span>' +
                    '</span>' +
                    '<span class="pos-line-total">' + escapeHtml(money(fromScaled(priced.total, 2))) + '</span>' +
                '</div>' +

                '<div class="pos-line-actions">' +
                    '<span class="pos-stepper">' +
                        '<button type="button" class="pos-stepper-btn" data-step="-1"' +
                            ' aria-label="' + escapeHtml(boot.labels.decrease) + '">&minus;</button>' +
                        '<input type="number" class="pos-stepper-input" data-field="quantity"' +
                            ' value="' + escapeHtml(qty(line.quantity)) + '"' +
                            ' step="0.0001" min="0.0001" inputmode="decimal"' +
                            ' aria-label="' + escapeHtml(fill(boot.labels.quantityFor, { name: line.product.name })) + '">' +
                        '<button type="button" class="pos-stepper-btn" data-step="1"' +
                            ' aria-label="' + escapeHtml(boot.labels.increase) + '">+</button>' +
                    '</span>' +
                    (low
                        ? '<span class="badge badge-warning">' + escapeHtml(boot.labels.aboveStock) + '</span>'
                        : '') +
                    '<button type="button" class="pos-stepper-btn pos-line-remove" data-remove' +
                        ' aria-label="' + escapeHtml(boot.labels.remove) + '">&times;</button>' +
                '</div>' +
            '</li>';
    }

    /** One line's money, in minor units. Shared by the renderer and the total. */
    function priceLine(line) {
        var unit = toScaled(line.product.price, 2);
        var quantity = toScaled(line.quantity, 2);
        var gross = Math.round((unit * quantity) / 10000);
        var discount = Math.min(toScaled(line.discount || 0, 2), gross);
        var net = gross - discount;
        var rate = parseFloat(line.product.tax_rate || 0);

        return {
            gross: gross,
            discount: discount,
            net: net,
            tax: Math.round((net * rate) / 100),
            total: net + Math.round((net * rate) / 100)
        };
    }

    function renderCart() {
        el.lines.innerHTML = state.lines.map(lineHtml).join('');

        // Keep the selection inside the cart: removing the last line must not
        // leave the keyboard pointing at a row that no longer exists.
        if (state.selectedIndex >= state.lines.length) {
            state.selectedIndex = state.lines.length - 1;
        }

        renderTotals();
    }

    /* ---------------- Cart mutations ---------------------------------------- */

    function addProduct(product) {
        if (!product) { return; }

        if (product.track_stock && !product.allow_negative_stock) {
            var inCart = state.lines
                .filter(function (l) { return l.product.id === product.id; })
                .reduce(function (sum, l) { return sum + (parseFloat(l.quantity) || 0); }, 0);

            var available = parseFloat(product.available || 0);

            if (inCart + 1 > available) {
                App.toast(
                    fill(boot.labels.stockWarning, { qty: qty(available) }),
                    'warning',
                    product.name
                );
                return;
            }
        }

        var existing = null;
        for (var i = 0; i < state.lines.length; i++) {
            if (state.lines[i].product.id === product.id) { existing = state.lines[i]; break; }
        }

        if (existing) {
            existing.quantity = (parseFloat(existing.quantity) || 0) + 1;
            // Select what was just touched, so +/- and Delete act on it.
            state.selectedIndex = state.lines.indexOf(existing);
        } else {
            state.lines.push({ uid: ++lineSeq, product: product, quantity: 1, discount: 0 });
            state.selectedIndex = state.lines.length - 1;
        }

        // Flash the tile so the eye can find what was just added in a grid of
        // dozens of identical-looking buttons.
        product.__flash = true;
        window.setTimeout(function () { product.__flash = false; }, 700);
        var flashId = product.id;
        window.setTimeout(function () {
            var node = el.grid.querySelector('[data-product-id="' + flashId + '"]');
            if (node) { node.classList.remove('is-flash'); }
        }, 700);

        renderCart();
    }

    function setQuantity(index, quantity) {
        var line = state.lines[index];
        if (!line) { return; }

        var value = parseFloat(quantity);
        if (isNaN(value) || value <= 0) { removeLine(index); return; }

        line.quantity = value;
        renderCart();
    }

    function stepQuantity(index, delta) {
        var line = state.lines[index];
        if (!line) { return; }

        var next = (parseFloat(line.quantity) || 0) + delta;
        setQuantity(index, Math.max(0, Math.round(next * 10000) / 10000));
    }

    function removeLine(index) {
        state.lines.splice(index, 1);
        if (state.selectedIndex >= state.lines.length) {
            state.selectedIndex = state.lines.length - 1;
        }
        renderCart();
    }

    function clearCart() {
        state.lines = [];
        state.payments = [];
        state.orderDiscount = 0;
        state.shipping = 0;
        state.selectedIndex = -1;
        state.idempotencyKey = newKey();
        state.customer = null;
        el.customerName.textContent = boot.labels.walkIn;
        el.customerOpen.classList.remove('is-set');

        renderCart();
        renderPayments();
        closeModals();
        focusSearch();
    }

    /* ---------------- Payments ---------------------------------------------- */

    function defaultMethodId() {
        return (boot.paymentMethods && boot.paymentMethods.length) ? boot.paymentMethods[0].id : null;
    }

    function findMethod(id) {
        var found = null;
        (boot.paymentMethods || []).forEach(function (m) {
            if (String(m.id) === String(id)) { found = m; }
        });
        return found;
    }

    /*
     * The tender method picker, rendered as the same custom combobox every
     * other dropdown on the site uses.
     *
     * A native <select> inside a modal paints with the operating system's own
     * widget, which is the one control in the tender sheet that cannot be made
     * to match anything around it. Rebuilt rather than reused, because this row
     * is re-rendered every time an amount changes.
     */
    function paymentMethodSelectHtml(method, index) {
        var id = 'pay-method-' + index;

        var options = (boot.paymentMethods || []).map(function (m) {
            return '<option value="' + m.id + '"' +
                (String(m.id) === String(method && method.id) ? ' selected' : '') + '>' +
                escapeHtml(m.name) + '</option>';
        }).join('');

        return '' +
            '<div class="select" data-select data-placeholder=""' +
                ' data-search-placeholder="' + escapeHtml(boot.labels.search) + '"' +
                ' data-empty-text="' + escapeHtml(boot.labels.noMatch) + '">' +
                '<select class="select-native" id="' + id + '" tabindex="-1" aria-hidden="true"' +
                    ' data-select-native="true" data-pay="method_id">' + options + '</select>' +
                '<div class="select-shell">' +
                    '<button type="button" class="select-trigger" aria-haspopup="listbox"' +
                        ' aria-expanded="false" aria-controls="' + id + '-listbox"' +
                        ' aria-labelledby="' + id + '-value">' +
                        '<span class="select-value" id="' + id + '-value">' +
                            escapeHtml(method ? method.name : '') + '</span>' +
                        '<svg class="select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
                            ' stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"' +
                            ' aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>' +
                    '</button>' +
                '</div>' +
                '<div class="select-panel" data-select-panel hidden>' +
                    '<div class="select-search-row"><div class="select-search-wrap">' +
                        '<svg class="select-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
                            ' stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
                            '<circle cx="11" cy="11" r="7"></circle>' +
                            '<line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' +
                        '<input type="text" class="select-search" data-select-search autocomplete="off"' +
                            ' spellcheck="false" role="combobox" aria-expanded="true"' +
                            ' aria-controls="' + id + '-listbox" placeholder="' +
                            escapeHtml(boot.labels.search) + '">' +
                    '</div></div>' +
                    '<ul class="select-list" id="' + id + '-listbox" data-select-list role="listbox"' +
                        ' tabindex="-1"></ul>' +
                '</div>' +
            '</div>';
    }

    /** The method a cashier reaches for first: cash, then anything else. */
    function preferredMethodId() {
        var methods = boot.paymentMethods || [];
        for (var i = 0; i < methods.length; i++) {
            if (String(methods[i].code || '').toLowerCase() === 'cash') { return methods[i].id; }
        }
        return methods.length ? methods[0].id : null;
    }

    function paymentRowHtml(payment, index, total) {
        var method = findMethod(payment.method_id);

        return '' +
            '<div class="pos-payment-row" data-payment-index="' + index + '">' +
                '<div class="pos-form-group">' +
                    '<label class="form-label" for="pay-method-' + index + '">' +
                        escapeHtml(boot.labels.method) + '</label>' +
                    paymentMethodSelectHtml(method, index) +
                '</div>' +

                '<div class="pos-form-group">' +
                    '<label class="form-label" for="pay-amount-' + index + '">' +
                        escapeHtml(boot.labels.amount) + '</label>' +
                    // payment.amount is held in minor units and an <input
                    // type=number> wants the decimal the cashier reads. A
                    // toScaled/fromScaled round trip here cancels out, which
                    // renders 5.52 as 552.00 - a figure the cashier then
                    // charges.
                    '<input type="number" class="form-control text-end" id="pay-amount-' + index + '"' +
                        ' data-pay="amount" step="0.01" min="0" inputmode="decimal" value="' +
                        escapeHtml(fromScaled(payment.amount, 2)) + '">' +
                '</div>' +

                (method && method.requires_reference
                    ? '<div class="pos-form-group is-wide">' +
                        '<label class="form-label" for="pay-ref-' + index + '">' +
                            escapeHtml(boot.labels.reference) + ' *</label>' +
                        '<input type="text" class="form-control" id="pay-ref-' + index + '"' +
                            ' data-pay="reference" maxlength="120" value="' +
                            escapeHtml(payment.reference || '') + '">' +
                      '</div>'
                    : '') +

                (total > 1
                    ? '<button type="button" class="pos-stepper-btn pos-line-remove" data-pay-remove' +
                        ' aria-label="' + escapeHtml(boot.labels.remove) + '">&times;</button>'
                    : '') +
            '</div>';
    }

    function renderPayments() {
        var t = computeTotals();

        if (state.payments.length === 0) {
            state.payments.push({
                method_id: preferredMethodId(),
                amount: t.balance > 0 ? t.balance : t.grand,
                tendered: t.balance > 0 ? t.balance : t.grand,
                reference: ''
            });
        }

        el.paymentRows.innerHTML = state.payments.map(function (payment, index) {
            return paymentRowHtml(payment, index, state.payments.length);
        }).join('');

        // The method picker is injected, so it is not covered by the page-level
        // enhance() pass. Without this it renders as a bare native select the
        // first time the tender sheet opens, and as the custom one afterwards.
        if (App.selects) { App.selects.init(el.paymentRows); }

        el.paymentDue.textContent = money(fromScaled(t.grand, 2));

        var balance = t.balance;
        el.balance.textContent = money(fromScaled(Math.abs(balance), 2));
        el.balance.parentElement.classList.toggle('is-credit', balance <= 0);
        el.change.textContent = money(fromScaled(t.change, 2));

        renderCashGrid();
    }

    /** Denominations derived from what is actually owed, not a fixed keypad. */
    function renderCashGrid() {
        var due = computeTotals().grand;
        if (due <= 0) { el.cashGrid.innerHTML = ''; return; }

        var notes = [1, 2, 5, 10, 20, 50, 100, 500, 1000];
        var suggestions = [];

        // Round numbers at or above the amount due, plus the amount itself.
        notes.forEach(function (value) {
            var note = value * 100;   // into minor units
            if (note > due && suggestions.length < 7) { suggestions.push(note); }
        });
        suggestions.unshift(due);

        // Cap at eight buttons so the grid stays a fixed shape.
        suggestions = suggestions.slice(0, 8);

        el.cashGrid.innerHTML = suggestions.map(function (value) {
            return '<button type="button" class="pos-cash-btn" data-cash="' + value + '">' +
                escapeHtml(money(fromScaled(value, 2))) + '</button>';
        }).join('');
    }

    /* ---------------- Customer picker ---------------------------------------- */

    function selectCustomer(customer) {
        state.customer = customer;
        el.customerName.textContent = customer ? customer.name : boot.labels.walkIn;
        el.customerOpen.classList.toggle('is-set', !!customer);
        renderCustomerResults([]);
        if (el.customerSearch) { el.customerSearch.value = ''; }
    }

    var customerTimer = null;

    function onCustomerSearch() {
        if (!el.customerSearch) { return; }

        var term = el.customerSearch.value.trim();

        window.clearTimeout(customerTimer);

        if (term.length < 2) {
            // Below two characters, search the pre-loaded roster instead of
            // asking the server — a customer list is already in memory.
            renderCustomerResults(filterCustomers(term));
            return;
        }

        customerTimer = window.setTimeout(function () {
            fetch(boot.customersUrl + '?q=' + encodeURIComponent(term), {
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    (data.customers || []).forEach(function (c) {
                        var existing = (boot.customers || []).filter(function (b) { return b.id === c.id; })[0];
                        if (existing) { existing.outstanding = c.outstanding; }
                    });
                    renderCustomerResults(data.customers || []);
                })
                .catch(function () { renderCustomerResults(filterCustomers(term)); });
        }, 250);
    }

    function filterCustomers(term) {
        var needle = term.toLowerCase();
        return (boot.customers || []).filter(function (c) {
            return (c.name || '').toLowerCase().indexOf(needle) !== -1
                || (c.phone || '').indexOf(needle) !== -1
                || (c.code || '').toLowerCase().indexOf(needle) !== -1;
        }).slice(0, 20);
    }

    function renderCustomerResults(customers) {
        if (!el.customerResults) { return; }

        if (!customers.length) {
            el.customerResults.innerHTML = '<div class="pos-result-empty">' +
                escapeHtml(boot.labels.noCustomer) + '</div>';
            return;
        }

        el.customerResults.innerHTML = customers.map(function (c) {
            return '<button type="button" class="pos-result" role="option" data-customer-id="' + c.id + '">' +
                '<span class="pos-result-name">' + escapeHtml(c.name) + '</span>' +
                '<span class="pos-result-meta">' +
                    escapeHtml([c.code, c.phone].filter(Boolean).join(' · ') || '—') +
                '</span>' +
            '</button>';
        }).join('');
    }

    /* ---------------- Product search ----------------------------------------- */

    var searchTimer = null;

    function onSearchInput() {
        var term = el.search.value.trim();
        state.query = term;

        window.clearTimeout(searchTimer);

        if (term.length === 0) {
            renderGrid(visibleProducts(boot.products || []));
            return;
        }

        // Local first, so typing never waits on the network — then reconcile
        // with the server, which knows products this terminal never loaded.
        renderGrid(visibleProducts(boot.products || []));

        searchTimer = window.setTimeout(function () {
            fetch(boot.searchUrl + '?q=' + encodeURIComponent(term), {
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var products = data.products || [];
                    (products || []).forEach(function (p) { catalog[p.id] = p; });

                    // A scanner types a full barcode then Enter: add it straight
                    // away rather than making the cashier click the tile.
                    if (data.exact && products.length === 1) {
                        addProduct(products[0]);
                        el.search.value = '';
                        state.query = '';
                        renderGrid(visibleProducts(boot.products || []));
                        return;
                    }

                    // Merge so a server hit is not lost when the local slice
                    // was only a partial view of the catalogue.
                    var merged = {};
                    (boot.products || []).forEach(function (p) { merged[p.id] = p; });
                    products.forEach(function (p) { merged[p.id] = p; });

                    var list = Object.keys(merged).map(function (k) { return merged[k]; });
                    renderGrid(visibleProducts(list));
                })
                .catch(function () { /* offline: the local slice stays on screen */ });
        }, 220);
    }

    /* ---------------- Modals -------------------------------------------------- */

    function openModal(id) {
        var node = byId(id);
        if (!node) { return; }
        node.hidden = false;

        var focusable = node.querySelector('input:not([type="hidden"]), select, button');
        if (focusable) { focusable.focus(); }
    }

    function closeModal(id) {
        var node = byId(id);
        if (node) { node.hidden = true; }
    }

    /*
     * Close every open modal.
     *
     * Driven off the DOM rather than a hand-kept list of ids. A named list is
     * correct only on the day it is written: the held-sales modal was added and
     * every list was left alone, so its close button, its backdrop and Esc all
     * did nothing while the three older modals worked fine. Enumerating
     * `.pos-modal` means a new modal is closable the moment it is rendered.
     */
    function closeModals() {
        Array.prototype.forEach.call(document.querySelectorAll('.pos-modal'), function (modal) {
            modal.hidden = true;
        });

        if (el.receiptModal && el.receiptModal.classList.contains('is-open')) {
            el.receiptModal.classList.remove('is-open');
        }
    }

    function anyModalOpen() {
        return document.querySelector('.pos-modal:not([hidden])') !== null
            || !!(el.receiptModal && el.receiptModal.classList.contains('is-open'));
    }

    /* ---------------- Held sales -------------------------------------------- */

    /*
     * Parking is a server round-trip, not a local one, because a hold has to
     * outlive the browser as well as the page. If the connection is down the
     * sale simply is not parked — parking it locally and syncing later would
     * need a second queue with its own conflict rules, and a cashier who walks
     * away from an un-persisted basket comes back to it still on screen.
     */
    function holdPayload() {
        return {
            label: boot.labels.holdPrefix + ' ' + new Date().toLocaleTimeString(),
            customer_id: state.customer ? state.customer.id : null,
            customer_name: state.customer ? state.customer.name : null,
            discount_total: fromScaled(state.orderDiscount, 2),
            shipping_total: fromScaled(state.shipping, 2),
            note: el.note ? el.note.value : null,
            warehouse_id: el.warehouseId ? el.warehouseId.value : null,
            cash_register_session_id: el.sessionId ? el.sessionId.value || null : null,
            lines: state.lines.map(function (line) {
                return {
                    product_id: line.product.id,
                    name: line.product.name,
                    quantity: String(line.quantity),
                    unit_price: String(line.product.price),
                    discount_amount: fromScaled(toScaled(line.discount || 0, 2), 2)
                };
            })
        };
    }

    function parkCart() {
        if (state.lines.length === 0) {
            App.toast(boot.labels.emptyCartHint, 'warning');
            return;
        }

        request(boot.holdsUrl, {
            method: 'POST',
            body: holdPayload()
        })
            .then(function (result) {
                if (!result.success) {
                    App.toast(result.message || 'The sale could not be held.', 'danger');
                    return;
                }

                clearCart();
                state.holds = result.holds || state.holds;
                renderHolds();
                openHolds();
                App.toast(boot.labels.heldSaved, 'success');
            })
            .catch(function () {
                App.toast(boot.labels.holdFailed, 'danger');
            });
    }

    function loadHolds() {
        return request(boot.holdsUrl, { method: 'GET' })
            .then(function (result) {
                state.holds = result.holds || [];
                renderHolds();
            })
            .catch(function () {
                // A failed listing must not empty the panel — leave whatever
                // was last known rather than implying there is nothing held.
                renderHolds();
            });
    }

    function renderHolds() {
        var holds = state.holds || [];
        var list = el.holdsList;

        if (el.holdsCount) {
            el.holdsCount.hidden = holds.length === 0;
            el.holdsCount.textContent = holds.length ? '(' + holds.length + ')' : '';
        }

        el.holdsEmpty.hidden = holds.length > 0;
        list.hidden = holds.length === 0;

        list.innerHTML = holds.map(function (hold, index) {
            return '<li class="pos-hold' + (index === 0 ? ' is-next' : '') + '" data-reference="' + escapeHtml(hold.reference) + '">' +
                '<span class="pos-hold-info">' +
                    '<span class="pos-hold-label">' + escapeHtml(hold.label) + '</span>' +
                    '<span class="pos-hold-meta">' +
                        escapeHtml(fill(boot.labels.itemCount, { count: hold.lines })) +
                        ' · ' + escapeHtml(money(hold.total)) +
                        ' · ' + escapeHtml(hold.parked_at_label || '') +
                        (hold.customer ? ' · ' + escapeHtml(hold.customer) : '') +
                    '</span>' +
                '</span>' +
                '<span class="pos-hold-actions">' +
                    '<button type="button" class="btn btn-primary btn-sm" data-hold-resume>' +
                        escapeHtml(boot.labels.resume) + '</button>' +
                    '<button type="button" class="btn btn-ghost btn-icon" data-hold-release' +
                        ' title="' + escapeHtml(boot.labels.discard) + '"' +
                        ' aria-label="' + escapeHtml(fill(boot.labels.discardNamed, { name: hold.label })) + '">&times;</button>' +
                '</span>' +
            '</li>';
        }).join('');
    }

    function openHolds() {
        loadHolds();
        openModal('pos-holds-modal');
    }

    function resumeHold(reference) {
        request(boot.holdsUrl + '/' + encodeURIComponent(reference) + '/resume', { method: 'POST' })
            .then(function (result) {
                if (!result.success) {
                    showHoldsError(result.message);
                    return;
                }

                state.holds = state.holds.filter(function (h) { return h.reference !== reference; });

                applyResumedCart(result.cart);
                closeModal('pos-holds-modal');
                renderHolds();
                focusSearch();
            })
            .catch(function () { showHoldsError(boot.labels.networkError); });
    }

    /** Put a held cart back, replacing whatever is on the till. */
    function applyResumedCart(cart) {
        state.lines = (cart.lines || []).map(function (line) {
            return {
                uid: ++lineSeq,
                product: catalog[line.product_id] || line,
                quantity: parseFloat(line.quantity) || 1,
                discount: parseFloat(line.discount) || 0
            };
        });

        // Register anything the terminal had not seen, so search still works.
        (cart.lines || []).forEach(function (line) { catalog[line.product_id] = line; });

        state.orderDiscount = toScaled(cart.discount_total || 0, 2);
        state.selectedIndex = state.lines.length ? 0 : -1;

        if (cart.customer_id) {
            var match = null;
            (boot.customers || []).forEach(function (c) {
                if (c.id === cart.customer_id) { match = c; }
            });
            selectCustomer(match || { id: cart.customer_id, name: cart.customer_name || 'Customer' });
        } else {
            selectCustomer(null);
        }

        if (el.note && cart.note) { el.note.value = cart.note; }

        state.payments = [];
        renderCart();
        renderPayments();
    }

    function releaseHold(reference) {
        request(boot.holdsUrl + '/' + encodeURIComponent(reference), { method: 'DELETE' })
            .then(function (result) {
                state.holds = result.holds || [];
                renderHolds();
            })
            .catch(function () { showHoldsError(boot.labels.networkError); });
    }

    function showHoldsError(message) {
        if (!el.holdsError) { return; }
        el.holdsError.hidden = !message;
        el.holdsError.textContent = message || '';
    }

    /** Small fetch wrapper that turns Laravel's JSON errors into a value. */
    function request(url, options) {
        var settings = options || {};

        return fetch(url, {
            method: settings.method || 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': boot.csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: settings.body ? JSON.stringify(settings.body) : undefined
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (body) {
                if (!response.ok) {
                    var first = body.errors ? Object.values(body.errors)[0] : null;
                    return {
                        success: false,
                        message: (first && first[0]) || body.message || null
                    };
                }

                return body;
            });
        });
    }

    /* ---------------- Fullscreen --------------------------------------------- */

    /*
     * A till is a single-purpose screen. Browser chrome, tabs and the OS taskbar
     * all take space the product grid needs, and on a 15" counter display that is
     * the difference between eight visible tiles and four.
     *
     * requestFullscreen needs a user gesture, which is why this hangs off a click
     * and off F11 and not off anything that runs on load. The pop-out is a
     * separate path: a second till on a second monitor is a real setup, and a
     * popup window can only go fullscreen after it has loaded, hence the query
     * flag the far side reads.
     */
    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    function toggleFullscreen() {
        if (isFullscreen()) {
            if (document.exitFullscreen) { document.exitFullscreen(); }
            else if (document.webkitExitFullscreen) { document.webkitExitFullscreen(); }
            return;
        }

        var target = document.documentElement;

        if (target.requestFullscreen) {
            // Rejected when the document is not focused or the gesture was
            // already spent; the caller gets a toast rather than silence.
            var attempt = target.requestFullscreen();

            if (attempt && attempt.catch) {
                attempt.catch(function () { App.toast(boot.labels.fullscreenUnavailable, 'warning'); });
            }
        } else if (target.webkitRequestFullscreen) {
            target.webkitRequestFullscreen();
        } else {
            App.toast(boot.labels.fullscreenUnavailable, 'warning');
        }
    }

    function renderFullscreenButton() {
        if (!el.fullscreen) { return; }

        var on = isFullscreen();

        el.fullscreen.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            (on
                ? '<path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M16 21v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/>'
                : '<path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M16 21h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>') +
            '</svg>';

        el.fullscreen.title = on ? boot.labels.exitFullscreen : boot.labels.fullscreen;
        el.fullscreen.setAttribute('aria-label', el.fullscreen.title);
    }

    function popOutTill() {
        var win = window.open(
            window.location.pathname + '?fullscreen=1',
            'meridian-till',
            'popup=yes,width=1280,height=900'
        );

        if (!win) {
            App.toast(boot.labels.popupBlocked, 'warning');
            return;
        }

        win.focus();
    }

    /* ---------------- Barcode scanner ---------------------------------------- */

    /*
     * Keyboard-wedge scanner capture.
     *
     * A USB or Bluetooth barcode scanner is a keyboard: it "types" the code in a
     * few milliseconds and finishes with Enter. Waiting for the search box to
     * have focus is the usual shortcut and it fails exactly when it matters --
     * the cashier clicks a tile, steps away, comes back and scans, and the code
     * goes nowhere while the trailing Enter fires whatever F-key they last hit.
     *
     * So a scan is recognised by timing, not by focus: the gap between two keys
     * never exceeds MAX_GAP_MS (no finger sustains that, every scanner does), the
     * burst ends with Enter, and it is at least MIN_SCAN_LENGTH characters.
     *
     * A recognised scan is consumed outright -- default action and every other
     * keydown handler are stopped -- because that trailing Enter must never open
     * the tender sheet with half a barcode sitting in the search box.
     */
    var MAX_GAP_MS = 75;
    var MIN_SCAN_LENGTH = 4;

    // Keys a scan in flight must swallow rather than act on. A Code 128 payload
    // can carry any ASCII character, so a burst that steps a quantity or voids a
    // line is not a hypothetical.
    var SCAN_SWALLOW = ['+', '=', '-', '_', 'Delete', 'Backspace', 'ArrowUp', 'ArrowDown', 'Tab'];

    var scanBuffer = '';
    var scanLastKeyAt = 0;

    function onScanKeyDown(event) {
        // Modifier combos are human shortcuts (Ctrl+V, Alt+Tab). Scanners emit
        // bare characters, so a modified key can only end a burst, never start
        // one.
        if (event.ctrlKey || event.metaKey || event.altKey) {
            scanBuffer = '';
            return;
        }

        // A held key is a person leaning on the keyboard, not a scanner.
        if (event.repeat) { return; }

        // A modal owns the flow. A scan must not change the cart underneath an
        // open tender sheet, which is how a customer ends up charged for an item
        // they never asked for.
        if (anyModalOpen()) {
            scanBuffer = '';
            return;
        }

        var now = (window.performance && window.performance.now) ? window.performance.now() : Date.now();
        var bursting = scanBuffer.length > 0 && (now - scanLastKeyAt) <= MAX_GAP_MS;

        if (!bursting) { scanBuffer = ''; }
        scanLastKeyAt = now;

        if (event.key === 'Enter') {
            var code = scanBuffer;
            scanBuffer = '';

            if (code.length >= MIN_SCAN_LENGTH) {
                event.preventDefault();
                event.stopImmediatePropagation();
                handleScan(code);
            }

            return;
        }

        if (event.key.length === 1) {
            scanBuffer += event.key;
            return;
        }

        // Any other key ends the burst -- except the cart shortcuts, which a
        // scan in flight must swallow rather than act on. Only outside a text
        // field: in there the keys belong to the cashier's own typing.
        if (!isTyping(event.target) && bursting && SCAN_SWALLOW.indexOf(event.key) !== -1) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }

        scanBuffer = '';
    }

    function handleScan(code) {
        // Local first: a till that has lost the network still has to sell, and
        // the catalogue it was handed is all it can check against.
        var match = null;
        var keys = Object.keys(catalog);

        for (var i = 0; i < keys.length; i++) {
            var product = catalog[keys[i]];

            if (String(product.barcode || '') === code || String(product.sku || '') === code) {
                match = product;
                break;
            }
        }

        if (match) {
            addProduct(match);
            flashScan(code, true);
            return;
        }

        // Not in the local slice: ask the server. What ships to a till is a
        // working set, not the whole book.
        fetch(boot.searchUrl + '?q=' + encodeURIComponent(code), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var products = data.products || [];
                products.forEach(function (p) { catalog[p.id] = p; });

                if (data.exact && products.length) {
                    addProduct(products[0]);
                    flashScan(code, true);
                    return;
                }

                flashScan(code, false);
            })
            .catch(function () { flashScan(code, false); });
    }

    /* A scan with nothing behind it is the one thing a cashier has to see
       immediately, and they have to see it without looking away from the cart --
       hence the search field itself rather than a toast in the corner. */
    function flashScan(code, ok) {
        if (!el.search) { return; }

        el.search.value = code;
        state.query = code;
        el.search.classList.add(ok ? 'is-scan-ok' : 'is-scan-miss');
        el.search.select();

        window.setTimeout(function () {
            el.search.classList.remove('is-scan-ok', 'is-scan-miss');

            if (el.search.value === code) {
                el.search.value = '';
                state.query = '';
                renderGrid(visibleProducts(boot.products || []));
            }
        }, ok ? 900 : 2600);
    }

    /* ---------------- Checkout ----------------------------------------------- */

    function newKey() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        return 'pos-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12);
    }

    function collectPayload() {
        return {
            warehouse_id: el.warehouseId || null,
            cash_register_session_id: el.sessionId || null,
            customer_id: state.customer ? state.customer.id : null,
            discount_total: fromScaled(state.orderDiscount, 2),
            shipping_total: fromScaled(state.shipping, 2),
            notes: el.note ? el.note.value || null : null,
            idempotency_key: state.idempotencyKey,
            items: state.lines.map(function (line) {
                return {
                    product_id: line.product.id,
                    quantity: String(line.quantity),
                    unit_price: String(line.product.price),
                    discount_amount: fromScaled(toScaled(line.discount || 0, 2), 2)
                };
            }),
            payments: state.payments.map(function (p) {
                return {
                    payment_method_id: p.method_id,
                    amount: fromScaled(toScaled(p.amount, 2), 2),
                    tendered_amount: fromScaled(toScaled(p.tendered, 2), 2),
                    reference: p.reference || null
                };
            })
        };
    }

    function validate(payload) {
        if (!payload.items.length) { return boot.labels.emptyCart; }
        if (!payload.payments.length) { return boot.labels.paymentRequired; }

        var totals = computeTotals();

        // Credit is a permission, not a default: an unpaid basket is only ever
        // accepted when this terminal has explicitly been allowed to allow it.
        if (totals.balance > 0 && !state.allowCredit) { return boot.labels.underpaid; }

        for (var i = 0; i < payload.payments.length; i++) {
            var method = findMethod(payload.payments[i].payment_method_id);
            if (method && method.requires_reference && !payload.payments[i].reference) {
                return fill(boot.labels.referenceRequired, { method: method.name });
            }
        }

        return null;
    }

    function showPaymentError(message) {
        if (!el.paymentError) { return; }
        el.paymentError.hidden = !message;
        el.paymentError.textContent = message || '';
    }

    function submitSale() {
        var payload = collectPayload();
        var problem = validate(payload);

        if (problem) {
            showPaymentError(problem);
            return;
        }

        showPaymentError('');
        el.complete.classList.add('is-loading');
        el.complete.disabled = true;

        fetch(boot.saleUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': boot.csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
            .then(function (response) {
                return response.json().then(function (body) {
                    return { ok: response.ok, body: body };
                });
            })
            .then(function (result) {
                el.complete.classList.remove('is-loading');
                el.complete.disabled = false;

                if (!result.ok || !result.body.success) {
                    queueOrFail(result.body.message || 'The sale could not be completed.');
                    return;
                }

                showReceipt(result.body);
                clearCart();
            })
            .catch(function () {
                el.complete.classList.remove('is-loading');
                el.complete.disabled = false;
                queueOrFail(boot.labels.networkError);
            });
    }

    /**
     * The offline branch is the whole reason this file never posts the cart
     * directly: a network failure and a validation failure are handled
     * identically unless offline mode is genuinely enabled, so a till cannot
     * silently swallow a rejected sale.
     */
    function queueOrFail(message) {
        if (App.offline && App.offline.enabled && App.offline.isOffline()) {
            var payload = collectPayload();

            App.offline.enqueueSale(payload).then(function () {
                App.toast(boot.labels.offlineQueued, 'warning', 'Offline');
                closeModal('pos-payment-modal');
                clearCart();
                setSyncState('pending', fill(boot.labels.pending, { count: 1 }));
            });

            return;
        }

        showPaymentError(message);
    }

    function showReceipt(body) {
        receiptUrl = body.receipt_url;
        el.receiptTitle.textContent = body.message || 'Sale completed.';
        el.receiptDetail.textContent = body.sale
            ? body.sale.invoice_number + ' · ' + money(body.sale.grand_total)
            : '';

        closeModal('pos-payment-modal');

        if (App.modal) { App.modal.open('#pos-receipt-modal'); }
    }

    /* ---------------- Sync strip --------------------------------------------- */

    function setSyncState(stateName, message, time) {
        if (!el.syncStrip) { return; }

        el.syncStrip.setAttribute('data-state', stateName);
        if (message) { el.syncMessage.textContent = message; }
        el.syncTime.textContent = time || '';
    }

    function relativeTime(date) {
        if (!date) { return ''; }

        var seconds = Math.round((Date.now() - date.getTime()) / 1000);
        if (seconds < 60) { return '· ' + seconds + 's'; }

        var minutes = Math.round(seconds / 60);
        if (minutes < 60) { return '· ' + minutes + 'm'; }

        return '· ' + Math.round(minutes / 60) + 'h';
    }

    var lastSyncedAt = null;

    function updateSyncStrip() {
        if (!App.offline || !App.offline.enabled) { return; }

        var offline = App.offline.isOffline();
        var pending = typeof App.offline.pendingCount === 'function'
            ? App.offline.pendingCount()
            : 0;

        if (offline) {
            setSyncState('offline', boot.labels.offline, relativeTime(lastSyncedAt));
            return;
        }

        if (typeof App.offline.isSyncing === 'function' && App.offline.isSyncing()) {
            setSyncState('syncing', fill(boot.labels.syncing, { count: pending }));
            return;
        }

        if (pending > 0) {
            setSyncState('pending', fill(boot.labels.pending, { count: pending }), relativeTime(lastSyncedAt));
            return;
        }

        setSyncState('synced', boot.labels.synced, lastSyncedAt ? relativeTime(lastSyncedAt) : '');
    }

    /* ---------------- Keyboard ----------------------------------------------- */

    function focusSearch() {
        if (el.search && !anyModalOpen()) {
            el.search.focus();
            el.search.select();
        }
    }

    function focusTotal() {
        if (!el.grand) { return; }
        el.grand.scrollIntoView({ block: 'nearest' });
        el.grand.classList.add('is-flash');
        window.setTimeout(function () { el.grand.classList.remove('is-flash'); }, 800);
    }

    function isTyping(target) {
        if (!target) { return false; }
        var tag = target.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;
    }

    function selectedLine() {
        if (state.selectedIndex < 0 || state.selectedIndex >= state.lines.length) { return null; }
        return state.lines[state.selectedIndex];
    }

    function onKeyDown(event) {
        // Esc always works: a cashier must never be trapped in a dialog.
        if (event.key === 'Escape') {
            event.preventDefault();

            if (anyModalOpen()) {
                closeModals();
                focusSearch();
                return;
            }

            if (state.query !== '') {
                el.search.value = '';
                state.query = '';
                renderGrid(visibleProducts(boot.products || []));
                return;
            }

            if (state.lines.length) { clearCart(); }
            return;
        }

        // Function keys are global: they must work with the mouse anywhere,
        // including while focus sits in the tender sheet's amount field.
        switch (event.key) {
            case 'F2':
                event.preventDefault();
                closeModals();
                focusSearch();
                return;

            case 'F4':
                event.preventDefault();
                openModal('pos-customer-modal');
                if (el.customerSearch) { el.customerSearch.focus(); el.customerSearch.select(); }
                renderCustomerResults(filterCustomers(''));
                return;

            case 'F9':
                event.preventDefault();
                if (state.lines.length) { openTender(); }
                return;

            // F8 toggles the hold list when nothing is on the cart, and parks
            // the cart when there is one. The cashier's intent is always "get
            // this basket out of my way for a moment", and which of the two
            // that means follows from whether a basket exists.
            case 'F8':
                event.preventDefault();
                if (state.lines.length) { parkCart(); } else { openHolds(); }
                return;

            case 'F10':
                event.preventDefault();
                focusTotal();
                return;

            // F11 is NOT handled here, on purpose. The browser already owns
            // that key, and preventDefault-ing it to run our own fullscreen
            // took a working browser feature away in order to offer a second
            // button that did the same thing. The pop-out is the one control
            // for "give the till its own screen".

            case 'Enter':
                // Only claim Enter inside the tender sheet; elsewhere a focused
                // button needs its normal click behaviour.
                if (!el.paymentModal.hidden && event.target.tagName !== 'BUTTON') {
                    event.preventDefault();
                    submitSale();
                }
                return;

            default:
                break;
        }

        if (isTyping(event.target) || anyModalOpen()) { return; }

        var line = selectedLine();

        switch (event.key) {
            case 'ArrowDown':
                if (state.lines.length) {
                    event.preventDefault();
                    state.selectedIndex = Math.min(state.selectedIndex + 1, state.lines.length - 1);
                    renderCart();
                }
                return;

            case 'ArrowUp':
                if (state.lines.length) {
                    event.preventDefault();
                    state.selectedIndex = Math.max(state.selectedIndex - 1, 0);
                    renderCart();
                }
                return;

            case '+':
            case '=':
                if (line) { event.preventDefault(); stepQuantity(state.selectedIndex, 1); }
                return;

            case '-':
            case '_':
                if (line) { event.preventDefault(); stepQuantity(state.selectedIndex, -1); }
                return;

            case 'Delete':
            case 'Backspace':
                if (line) { event.preventDefault(); removeLine(state.selectedIndex); }
                return;

            default:
                return;
        }
    }

    /* ---------------- Wiring --------------------------------------------------- */

    function openTender() {
        var t = computeTotals();
        if (t.lineCount === 0) { return; }

        // Seed the first tender with the exact amount, so the common case is
        // one keypress rather than typing a figure the till already knows.
        state.payments = [{
            method_id: preferredMethodId(),
            amount: t.grand,
            tendered: t.grand,
            reference: ''
        }];

        showPaymentError('');
        renderPayments();
        openModal('pos-payment-modal');
    }

    function bind() {
        // ---- Catalog ----
        if (el.search) {
            el.search.addEventListener('input', onSearchInput);

            el.search.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') { return; }
                event.preventDefault();

                // Enter adds the best match — the fast path a scanner drives.
                var first = el.grid.querySelector('[data-product-id]');
                if (first) { addProduct(catalog[first.getAttribute('data-product-id')]); }
            });
        }

        el.grid.addEventListener('click', function (event) {
            var button = event.target.closest('[data-product-id]');
            if (!button) { return; }
            addProduct(catalog[button.getAttribute('data-product-id')]);
        });

        if (el.filters) {
            el.filters.addEventListener('click', function (event) {
                var chip = event.target.closest('[data-category]');
                if (!chip) { return; }

                state.categoryId = chip.getAttribute('data-category');

                Array.prototype.forEach.call(el.filters.children, function (node) {
                    node.classList.toggle('is-active', node === chip);
                });

                renderGrid(visibleProducts(boot.products || []));
            });
        }

        // ---- Cart lines ----
        el.lines.addEventListener('click', function (event) {
            var line = event.target.closest('[data-index]');
            if (!line) { return; }

            var index = parseInt(line.getAttribute('data-index'), 10);

            var step = event.target.closest('[data-step]');
            if (step) {
                event.stopPropagation();
                stepQuantity(index, parseInt(step.getAttribute('data-step'), 10));
                return;
            }

            var remove = event.target.closest('[data-remove]');
            if (remove) {
                event.stopPropagation();
                removeLine(index);
                return;
            }

            state.selectedIndex = index;
            renderCart();
        });

        el.lines.addEventListener('change', function (event) {
            var input = event.target.closest('[data-field="quantity"]');
            if (!input) { return; }

            var index = parseInt(input.closest('[data-index]').getAttribute('data-index'), 10);
            setQuantity(index, input.value);
        });

        if (el.clearCart) {
            el.clearCart.addEventListener('click', function () {
                if (state.lines.length && !window.confirm(boot.labels.confirmClear)) { return; }
                clearCart();
            });
        }

        // ---- Tender ----
        if (el.charge) { el.charge.addEventListener('click', openTender); }
        if (el.complete) { el.complete.addEventListener('click', submitSale); }

        if (el.openPayment) {
            el.openPayment.addEventListener('click', function () {
                if (state.lines.length === 0) { return; }
                el.discountInput.value = fromScaled(state.orderDiscount, 2);
                openModal('pos-discount-modal');
            });
        }

        if (el.discountApply) {
            el.discountApply.addEventListener('click', function () {
                var value = Math.max(0, parseFloat(el.discountInput.value) || 0);
                state.orderDiscount = toScaled(value, 2);
                closeModal('pos-discount-modal');
                renderCart();
            });
        }

        el.paymentRows.addEventListener('change', function (event) {
            var input = event.target.closest('[data-pay]');
            if (!input) { return; }

            var index = parseInt(input.closest('[data-payment-index]').getAttribute('data-payment-index'), 10);
            var field = input.getAttribute('data-pay');
            var payment = state.payments[index];
            if (!payment) { return; }

            if (field === 'amount') {
                payment.amount = Math.max(0, toScaled(input.value, 2));
                payment.tendered = payment.amount;
            } else if (field === 'tendered') {
                payment.tendered = Math.max(0, toScaled(input.value, 2));
            } else if (field === 'method_id') {
                payment.method_id = parseInt(input.value, 10);
            } else {
                payment[field] = input.value;
            }

            renderPayments();
        });

        el.paymentRows.addEventListener('click', function (event) {
            if (!event.target.closest('[data-pay-remove]')) { return; }
            state.payments.pop();
            renderPayments();
        });

        if (el.addPayment) {
            el.addPayment.addEventListener('click', function () {
                var outstanding = Math.max(0, computeTotals().balance);

                state.payments.push({
                    method_id: defaultMethodId(),
                    amount: outstanding,
                    tendered: outstanding,
                    reference: ''
                });

                renderPayments();
            });
        }

        el.cashGrid.addEventListener('click', function (event) {
            var button = event.target.closest('[data-cash]');
            if (!button) { return; }

            var amount = parseInt(button.getAttribute('data-cash'), 10);
            var payment = state.payments[state.payments.length - 1];
            if (!payment) { return; }

            payment.amount = amount;
            payment.tendered = amount;
            renderPayments();
        });

        // ---- Customer ----
        if (el.customerOpen) {
            el.customerOpen.addEventListener('click', function () {
                openModal('pos-customer-modal');
                renderCustomerResults(filterCustomers(''));
                if (el.customerSearch) { el.customerSearch.focus(); }
            });
        }

        if (el.customerSearch) { el.customerSearch.addEventListener('input', onCustomerSearch); }

        if (el.customerClear) {
            el.customerClear.addEventListener('click', function () { selectCustomer(null); });
        }

        if (el.customerResults) {
            el.customerResults.addEventListener('click', function (event) {
                var button = event.target.closest('[data-customer-id]');
                if (!button) { return; }

                var id = parseInt(button.getAttribute('data-customer-id'), 10);
                var match = null;

                (boot.customers || []).forEach(function (c) {
                    if (c.id === id) { match = c; }
                });

                if (match) {
                    selectCustomer(match);
                    closeModal('pos-customer-modal');
                    focusSearch();
                }
            });
        }

        // ---- Held sales ----
        if (el.hold) {
            el.hold.addEventListener('click', parkCart);
        }

        if (el.holdsOpen) {
            el.holdsOpen.addEventListener('click', openHolds);
        }

        if (el.holdsList) {
            el.holdsList.addEventListener('click', function (event) {
                var row = event.target.closest('[data-reference]');
                if (!row) { return; }

                var reference = row.getAttribute('data-reference');

                if (event.target.closest('[data-hold-resume]')) {
                    resumeHold(reference);
                    return;
                }

                if (event.target.closest('[data-hold-release]')) {
                    releaseHold(reference);
                }
            });
        }

        // ---- Credit toggle ----
        if (el.allowCredit && el.creditToggle) {
            el.allowCredit.addEventListener('change', function () {
                state.allowCredit = el.allowCredit.checked;

                // The switch paints itself through :checked, so there is no
                // state class to set here any more. What is left is telling the
                // page that the cart's due total is now collectable.
                state.allowCredit = el.allowCredit.checked;
            });
        }

        // ---- Backdrops and Esc ----
        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-pos-close]')) { closeModals(); focusSearch(); }
        });

        document.addEventListener('keydown', onKeyDown);

        // Capture phase, and ahead of onKeyDown above: a recognised scan is
        // consumed before the shortcut handler ever sees it, so a barcode that
        // ends in Enter cannot open the tender sheet.
        document.addEventListener('keydown', onScanKeyDown, true);

        document.addEventListener('fullscreenchange', renderFullscreenButton);

        // ---- Fullscreen / pop out ----
        if (el.fullscreen) {
            el.fullscreen.addEventListener('click', toggleFullscreen);
        }

        if (el.popout) {
            el.popout.addEventListener('click', popOutTill);
        }

        // A popped-out till asks for fullscreen through the query flag. The
        // request waits for the load to settle: a popup is not eligible for
        // fullscreen until it has a document, and asking during parse is
        // rejected outright.
        if (boot.enterFullscreen) {
            window.setTimeout(function () {
                if (!isFullscreen()) { toggleFullscreen(); }
            }, 60);
        }

        // ---- Receipt ----
        if (el.receiptOpen) {
            el.receiptOpen.addEventListener('click', function () {
                if (receiptUrl) { window.location.href = receiptUrl; }
            });
        }

        if (el.newSale) {
            el.newSale.addEventListener('click', function () {
                if (App.modal) { App.modal.close('#pos-receipt-modal'); }
                focusSearch();
            });
        }
    }

    /* ---------------- Boot ----------------------------------------------------- */

    state.idempotencyKey = newKey();

    renderGrid(visibleProducts(boot.products || []));
    renderCart();
    renderPayments();
    renderHolds();
    bind();

    // A held count the cashier can see from across the counter, loaded once at
    // boot and refreshed whenever the list is opened.
    loadHolds();

    // Offline support. Caching the catalogue means the grid still works when the
    // connection drops; enqueueSale() keeps the sale and pushes it later.
    if (App.offline) {
        App.offline.init({
            enabled: !!(boot.offline && boot.offline.enabled),
            syncUrl: boot.syncUrl || '/sync/receive',
            csrf: boot.csrf
        });

        App.offline.cacheCatalog(boot.products || []);

        if (el.syncNow) {
            el.syncNow.addEventListener('click', function () { App.offline.sync(true); });
        }

        // Poll only while the state could change. The strip is the one place a
        // cashier looks for "is this sale safe?", so it must not go stale.
        window.setInterval(updateSyncStrip, 5000);
        updateSyncStrip();

        window.addEventListener('online', updateSyncStrip);
        window.addEventListener('offline', updateSyncStrip);
    }

    focusSearch();

    App.pos = {
        state: state,
        computeTotals: computeTotals,
        money: money
    };
})();