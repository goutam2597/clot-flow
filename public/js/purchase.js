/* ==========================================================================
   Meridian POS — Purchase line editor
   Vanilla JS. Like the POS terminal, the numbers shown here are a preview:
   PurchaseController::calculateLines() recalculates every line server-side
   before anything is written.
   ========================================================================== */
(function () {
    'use strict';

    var bootEl = document.getElementById('purchase-bootstrap');
    if (!bootEl) { return; }

    var boot = JSON.parse(bootEl.textContent);
    var App = window.Meridian || (window.Meridian = {});

    var table = document.getElementById('purchase-lines');
    if (!table) { return; }

    var tbody = table.querySelector('tbody');
    var addButton = document.getElementById('purchase-add-line');
    var subtotalEl = document.getElementById('purchase-subtotal');
    var taxEl = document.getElementById('purchase-tax');
    var totalEl = document.getElementById('purchase-total');

    var currency = boot.currency || {};
    var decimals = currency.decimals === undefined ? 2 : currency.decimals;

    var products = boot.products || [];
    var taxRates = boot.taxRates || [];

    var orderDiscount = parseFloat(boot.old.discount_total) || 0;
    var shipping = parseFloat(boot.old.shipping_total) || 0;

    /* ---------------- Decimal helpers ------------------------------------- */
    function toScaled(value) {
        var n = parseFloat(value);
        if (isNaN(n)) { return 0; }
        return Math.round(n * Math.pow(10, decimals));
    }

    function fromScaled(value) {
        return (value / Math.pow(10, decimals)).toFixed(decimals);
    }

    function money(value) {
        var v = toScaled(value);
        var negative = v < 0;
        var abs = Math.abs(v).toString();

        // Zero-pad to at least one whole digit so sub-unit amounts format
        // correctly ($0.07 rather than $.7).
        while (abs.length <= decimals) { abs = '0' + abs; }

        var grouped = currency.thousand
            ? abs.replace(/\B(?=(\d{3})+(?!\d))/g, currency.thousand)
            : abs;

        var whole = grouped.slice(0, grouped.length - decimals);
        var frac = grouped.slice(grouped.length - decimals);
        var body = decimals > 0 ? whole + (currency.decimal || '.') + frac : whole;

        return (negative ? '-' : '') + (currency.position === 'after'
            ? body + currency.symbol
            : currency.symbol + body);
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
    }

    function taxRateFor(id) {
        var found = null;
        taxRates.forEach(function (t) { if (String(t.id) === String(id)) { found = t; } });
        return found;
    }

    function productById(id) {
        var found = null;
        products.forEach(function (p) { if (String(p.id) === String(id)) { found = p; } });
        return found;
    }

    /* ---------------- Lines ------------------------------------------------ */
    function readLines() {
        return Array.prototype.map.call(tbody.querySelectorAll('tr[data-index]'), function (tr) {
            var productId = tr.querySelector('[name$="[product_id]"]');
            var quantity = tr.querySelector('[name$="[quantity]"]');
            var unitCost = tr.querySelector('[name$="[unit_cost]"]');
            var discount = tr.querySelector('[name$="[discount_amount]"]');
            var tax = tr.querySelector('[name$="[tax_rate_id]"]');

            return {
                productId: productId ? productId.value : '',
                quantity: quantity ? parseFloat(quantity.value) || 0 : 0,
                unitCost: unitCost ? parseFloat(unitCost.value) || 0 : 0,
                discount: discount ? parseFloat(discount.value) || 0 : 0,
                taxRateId: tax ? tax.value : ''
            };
        }).filter(function (line) { return line.productId !== ''; });
    }

    function lineTotals(line) {
        var rate = taxRateFor(line.taxRateId);
        var percent = rate ? (parseFloat(rate.rate) || 0) : 0;

        var gross = toScaled(line.unitCost) * line.quantity;
        var grossRounded = Math.round(gross);
        var lineDiscount = Math.min(toScaled(line.discount), Math.max(grossRounded, 0));
        var net = grossRounded - lineDiscount;
        var tax = Math.round((net * percent) / 100);

        return { net: net, tax: tax, total: net + tax };
    }

    function renderTotals() {
        var subtotal = 0;
        var tax = 0;

        readLines().forEach(function (line) {
            var t = lineTotals(line);
            subtotal += t.net;
            tax += t.tax;
        });

        var orderDisc = Math.min(toScaled(orderDiscount), Math.max(subtotal, 0));
        subtotal -= orderDisc;

        var grand = subtotal + tax + toScaled(shipping);

        subtotalEl.textContent = money(fromScaled(subtotal));
        taxEl.textContent = money(fromScaled(tax));
        totalEl.textContent = money(fromScaled(grand));
    }

    function productOptions(selectedId) {
        var html = '<option value="">' + escapeHtml(boot.labels.chooseProduct) + '</option>';

        products.forEach(function (p) {
            var selected = String(p.id) === String(selectedId) ? ' selected' : '';
            html += '<option value="' + p.id + '"' + selected + '>' +
                escapeHtml(p.name) + ' (' + escapeHtml(p.sku) + ')</option>';
        });

        return html;
    }

    function taxOptions(selectedId) {
        var html = '<option value="">' + escapeHtml(boot.labels.noTax) + '</option>';

        taxRates.forEach(function (t) {
            var selected = String(t.id) === String(selectedId) ? ' selected' : '';
            html += '<option value="' + t.id + '"' + selected + '>' + escapeHtml(t.name) + '</option>';
        });

        return html;
    }

    function addLine(line) {
        var data = line || { productId: '', quantity: 1, unitCost: 0, discount: 0, taxRateId: '' };
        var index = tbody.querySelectorAll('tr[data-index]').length;

        var tr = document.createElement('tr');
        tr.setAttribute('data-index', index);
        tr.innerHTML =
            '<td>' +
                '<select class="form-control form-select" name="items[' + index + '][product_id]" data-line="product_id">' +
                    productOptions(data.productId) +
                '</select>' +
            '</td>' +
            '<td class="num">' +
                '<input type="number" class="form-control text-end" name="items[' + index + '][quantity]" ' +
                    'data-line="quantity" step="0.0001" min="0.0001" value="' + escapeHtml(data.quantity) + '">' +
            '</td>' +
            '<td class="num">' +
                '<input type="number" class="form-control text-end" name="items[' + index + '][unit_cost]" ' +
                    'data-line="unit_cost" step="0.0001" min="0" value="' + escapeHtml(fromScaled(toScaled(data.unitCost))) + '">' +
            '</td>' +
            '<td class="num">' +
                '<input type="number" class="form-control text-end" name="items[' + index + '][discount_amount]" ' +
                    'data-line="discount_amount" step="0.01" min="0" value="' + escapeHtml(fromScaled(toScaled(data.discount))) + '">' +
            '</td>' +
            '<td>' +
                '<select class="form-control form-select" name="items[' + index + '][tax_rate_id]" data-line="tax_rate_id">' +
                    taxOptions(data.taxRateId) +
                '</select>' +
            '</td>' +
            '<td class="num financial fw-semibold" data-line-total>0.00</td>' +
            '<td class="cell-actions">' +
                '<button type="button" class="btn btn-ghost btn-sm btn-icon" data-remove-line aria-label="Remove">&times;</button>' +
            '</td>';

        tbody.appendChild(tr);
        refresh();
    }

    function refresh() {
        readLines().forEach(function (line) {
            var tr = tbody.querySelector('tr[data-index="' + lineIndex(line) + '"]');
            if (!tr) { return; }
            var cell = tr.querySelector('[data-line-total]');
            if (cell) { cell.textContent = money(fromScaled(lineTotals(line).total)); }
        });

        renderTotals();
    }

    function lineIndex(line) {
        var rows = readLines();
        return rows.indexOf(line);
    }

    function removeLine(index) {
        var row = tbody.querySelector('tr[data-index="' + index + '"]');
        if (row) { row.remove(); }

        // Re-index so the submitted array has no gaps.
        Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-index]'), function (tr, i) {
            tr.setAttribute('data-index', i);
            Array.prototype.forEach.call(tr.querySelectorAll('[name]'), function (input) {
                input.name = input.name.replace(/items\[\d+\]/, 'items[' + i + ']');
            });
        });

        refresh();
    }

    /* ---------------- Wiring ----------------------------------------------- */
    tbody.addEventListener('change', function (event) {
        var input = event.target.closest('[data-line]');
        if (!input) { return; }

        var field = input.getAttribute('data-line');

        // Choosing a product fills the cost and tax from the product record.
        if (field === 'product_id' && input.value) {
            var product = productById(input.value);
            var tr = input.closest('tr');
            if (product) {
                var cost = tr.querySelector('[data-line="unit_cost"]');
                var tax = tr.querySelector('[data-line="tax_rate_id"]');
                if (cost) { cost.value = fromScaled(toScaled(product.cost)); }
                if (tax && product.tax_rate_id) { tax.value = product.tax_rate_id; }
            }
        }

        refresh();
    });

    tbody.addEventListener('click', function (event) {
        if (!event.target.closest('[data-remove-line]')) { return; }
        var index = parseInt(event.target.closest('tr').getAttribute('data-index'), 10);
        removeLine(index);
    });

    addButton.addEventListener('click', function () { addLine(); });

    Array.prototype.forEach.call(document.querySelectorAll('input[name="discount_total"], input[name="shipping_total"]'), function (input) {
        input.addEventListener('change', function () {
            var value = Math.max(0, parseFloat(input.value) || 0);
            input.value = value.toFixed(2);

            if (input.name === 'discount_total') { orderDiscount = value; }
            else { shipping = value; }

            renderTotals();
        });
    });

    /* ---------------- Restore after a validation error --------------------- */
    (function restore() {
        var old = boot.old.items || [];

        if (old.length) {
            old.forEach(function (item) {
                addLine({
                    productId: item.product_id || '',
                    quantity: parseFloat(item.quantity) || 1,
                    unitCost: parseFloat(item.unit_cost) || 0,
                    discount: parseFloat(item.discount_amount) || 0,
                    taxRateId: item.tax_rate_id || ''
                });
            });
        } else {
            addLine();
        }
    })();

    App.purchaseEditor = { addLine: addLine, recalculate: renderTotals };
})();
