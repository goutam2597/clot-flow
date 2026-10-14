/* ==========================================================================
   Meridian POS - Bulk actions

   Enhances a list so rows can be ticked in bulk. Three things it adds on top of
   the form that is already in the markup:

     * a header checkbox that ticks everything and reflects a partial selection
       in its own indeterminate state, so "some of these are selected" looks
       different from "all of these are selected";
     * a bar that appears only when something is ticked, carrying the count and
       the refusal names when the server spared any of them;
     * a real fetch to the bulk endpoint, because the answer is per row. A plain
       form POST cannot say "37 deleted, 3 refused" - and that answer is the
       whole point of the feature.

   Without this file the bar stays hidden and the checkboxes do nothing, which
   is the correct no-JS degradation for a destructive action.
   ========================================================================== */
(function () {
    'use strict';

    var App = window.Meridian = window.Meridian || {};

    var LABELS = {
        selectedOne: '1 selected',
        selectedMany: ':count selected',
        nothingSelected: 'Nothing selected',
        confirmOne: 'Delete 1 item? This cannot be undone.',
        confirmMany: 'Delete :count items? This cannot be undone.',
        deletedOne: '1 item deleted.',
        deletedMany: ':count items deleted.',
        partial: ':deleted deleted, :refused could not be.',
        refused: 'Kept:',
        failed: 'Nothing was deleted. Check the message above.',
        networkError: 'The request could not be sent. Nothing was deleted.',
    };

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
    }

    function fill(template, map) {
        return String(template).replace(/:([a-zA-Z_]+)/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : match;
        });
    }

    function Bulk(form) {
        this.form = form;
        this.endpoint = form.getAttribute('data-bulk-endpoint');
        this.subject = form.getAttribute('data-bulk-subject') || 'records';
        this.bar = form.querySelector('[data-bulk-bar]');
        this.count = form.querySelector('[data-bulk-count]');
        this.submit = form.querySelector('[data-bulk-submit]');
        this.clear = form.querySelector('[data-bulk-clear]');
        this.inputs = form.querySelector('[data-bulk-inputs]');
        this.table = document.querySelector('[data-bulk-table]');

        // The row checkboxes live in the table, not inside the form, so the
        // scope is the nearest common ancestor rather than the form itself.
        this.scope = form.closest('[data-bulk-scope]') || document;

        this.boxes = Array.prototype.slice.call(this.scope.querySelectorAll('[data-bulk-row]'));
        this.master = this.scope.querySelector('[data-bulk-all]');

        this.bind();
        this.sync();
    }

    Bulk.prototype.selected = function () {
        return this.boxes.filter(function (box) { return box.checked; });
    };

    Bulk.prototype.sync = function () {
        var chosen = this.selected();
        var count = chosen.length;
        var all = this.boxes.length > 0 && count === this.boxes.length;

        this.form.hidden = count === 0;

        if (this.count) {
            this.count.textContent = count === 0
                ? LABELS.nothingSelected
                : fill(count === 1 ? LABELS.selectedOne : LABELS.selectedMany, { count: count });
        }

        if (this.submit) { this.submit.disabled = count === 0; }

        // The master checkbox reflects three states, and the third one is the
        // reason the header checkbox exists at all: without it there is no way
        // to tell "all selected" from "most selected" at a glance.
        if (this.master) {
            this.master.checked = all;
            this.master.indeterminate = count > 0 && !all;

            if (typeof window.App !== 'undefined' && window.App.syncIndeterminate) {
                window.App.syncIndeterminate(this.master);
            } else {
                this.master.setAttribute('data-indeterminate', this.master.indeterminate ? 'true' : 'false');
            }
        }

        // Rebuild the posted values from the ticked boxes. These are real
        // hidden inputs rather than a serialised blob, so the server side is a
        // plain array validation and a no-JS fallback posts the same shape.
        if (this.inputs) {
            this.inputs.innerHTML = chosen.map(function (box) {
                return '<input type="hidden" name="ids[]" value="' + escapeHtml(box.value) + '">';
            }).join('');
        }

        return count;
    };

    Bulk.prototype.bind = function () {
        var self = this;

        this.scope.addEventListener('change', function (event) {
            if (event.target.matches('[data-bulk-row]')) {
                self.sync();
                return;
            }

            if (event.target.matches('[data-bulk-all]')) {
                var want = event.target.checked;
                self.boxes.forEach(function (box) { box.checked = want; });
                self.sync();
            }
        });

        if (this.clear) {
            this.clear.addEventListener('click', function () {
                self.boxes.forEach(function (box) { box.checked = false; });
                self.sync();
            });
        }

        this.form.addEventListener('submit', function (event) {
            event.preventDefault();
            self.send();
        });
    };

    Bulk.prototype.send = function () {
        var self = this;
        var chosen = this.selected();

        if (chosen.length === 0) { return; }

        var question = chosen.length === 1
            ? LABELS.confirmOne
            : fill(LABELS.confirmMany, { count: chosen.length });

        if (!window.confirm(question)) { return; }

        var ids = chosen.map(function (box) { return box.value; });

        if (this.submit) { this.submit.classList.add('is-loading'); }

        fetch(this.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': App.csrf ? App.csrf() : '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ ids: ids })
        })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    return { status: response.status, body: body };
                });
            })
            .then(function (result) {
                if (result.status === 419 || result.status === 401) {
                    throw new Error('session');
                }

                self.report(result.body);
                self.reload();
            })
            .catch(function () {
                App.toast(LABELS.networkError, 'danger');
                if (self.submit) { self.submit.classList.remove('is-loading'); }
            });
    };

    /*
     * What to say afterwards.
     *
     * The partial case is the one worth reading carefully. The server returns
     * 207 when it deleted some rows and refused others, and the refusals are
     * named: "kept Foo (has stock)". Showing a bare "some could not be deleted"
     * would leave the user guessing whether to retry, and retrying is exactly
     * how the second pass deletes things the first pass spared.
     */
    Bulk.prototype.report = function (body) {
        var refused = body.refused || [];
        var deleted = body.deleted || 0;

        if (!body.success && deleted === 0) {
            App.toast(this.refusalText(refused) || LABELS.failed, 'danger', LABELS.refused);
            return;
        }

        if (refused.length > 0) {
            App.toast(
                fill(LABELS.partial, { deleted: deleted, refused: refused.length })
                    + ' ' + this.refusalText(refused),
                'warning',
                LABELS.refused
            );
            return;
        }

        App.toast(deleted === 1 ? LABELS.deletedOne : fill(LABELS.deletedMany, { count: deleted }), 'success');
    };

    Bulk.prototype.refusalText = function (refused) {
        if (!refused.length) { return ''; }

        return refused.slice(0, 5).map(function (row) {
            return row.name + ' - ' + row.reason;
        }).join('; ') + (refused.length > 5 ? ' (+' + (refused.length - 5) + ')' : '');
    };

    Bulk.prototype.reload = function () {
        // A full reload rather than removing rows by hand: the list is
        // paginated, filtered and permission-filtered, so what survives a
        // delete is not simply "the page minus these rows".
        window.location.reload();
    };

    App.bulk = {
        instances: [],

        init(root) {
            var scope = root || document;

            Array.prototype.forEach.call(
                scope.querySelectorAll('[data-bulk-form]'),
                function (form) {
                    App.bulk.instances.push(new Bulk(form));
                }
            );

            return App.bulk;
        }
    };

    App.initBulk = function () { return App.bulk.init(); };

    if (document.readyState !== 'loading') { App.bulk.init(); }
    else { document.addEventListener('DOMContentLoaded', function () { App.bulk.init(); }); }
})();
