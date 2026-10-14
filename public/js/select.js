/* ==========================================================================
   Meridian POS — Custom select (combobox)

   Replaces the chrome of every <select> on the site with a searchable listbox,
   while leaving the real <select> in the form to do what only it can do: carry
   the value into the POST and render on its own when this script never loads.

   Nothing here is required for a form to submit. Every field still works with
   this file blocked; it just stops filtering and starts looking native.

   Behaviour it has to match, because people already know it from every other
   dropdown they use: type to filter, arrows to move, Home/End to jump, Enter
   to choose, Escape to back out, click-away to dismiss, Tab to leave.
   ========================================================================== */
(function () {
    'use strict';

    var App = window.Meridian = window.Meridian || {};

    var OPEN = [];

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function byId(id) { return document.getElementById(id); }

    /* ---- One field --------------------------------------------------------- */

    function Select(root) {
        this.root = root;
        this.native = root.querySelector('[data-select-native]');
        this.shell = root.querySelector('.select-shell');
        this.trigger = root.querySelector('.select-trigger');
        this.panel = root.querySelector('[data-select-panel]');
        this.list = root.querySelector('[data-select-list]');
        this.search = root.querySelector('[data-select-search]');
        this.label = root.querySelector('.select-value');
        this.placeholder = root.getAttribute('data-placeholder') || '';
        this.emptyText = root.getAttribute('data-empty-text') || 'No matches';
        this.searchPlaceholder = root.getAttribute('data-search-placeholder') || 'Search…';
        this.required = root.querySelector('[data-required]') !== null;
        this.options = [];
        this.filtered = [];
        this.active = -1;
        this.open = false;
    }

    /* Read the options out of the native control.

       The native element is the single source of truth on purpose: a script
       elsewhere on the page can rewrite `select.innerHTML` to add a payment
       method or swap a warehouse, and a listbox that cached its own copy would
       silently diverge from what actually POSTs. */
    Select.prototype.readOptions = function () {
        var self = this;
        var out = [];

        Array.prototype.forEach.call(this.native.options, function (option) {
            if (option.hidden) { return; }
            out.push({
                value: option.value,
                label: (option.textContent || '').trim(),
                group: option.getAttribute('data-group') || '',
                disabled: option.disabled,
                placeholder: option.value === '' && out.length === 0
            });
        });

        this.options = out;
    };

    Select.prototype.selectedOption = function () {
        var value = this.native.value;
        for (var i = 0; i < this.options.length; i++) {
            if (this.options[i].value === value) { return this.options[i]; }
        }
        return null;
    };

    Select.prototype.syncLabel = function () {
        var option = this.selectedOption();
        var text = option && !option.placeholder ? option.label : this.placeholder;

        this.label.textContent = text || '';
        this.label.classList.toggle('is-placeholder', !text);
        this.root.classList.toggle('has-value', !!option && !option.placeholder);
        this.syncClear();
    };

    /* The clear affordance is rebuilt rather than toggled: whether it exists
       depends on the value, and a select can change its own value from anywhere
       on the page. It is a real <button>, and a sibling of the trigger rather
       than a child of it — a button nested in a button is invalid HTML and
       exposes the control twice. */
    Select.prototype.syncClear = function () {
        var existing = this.shell.querySelector('[data-select-clear]');
        var option = this.selectedOption();
        var wanted = !!option && !option.placeholder && !this.native.disabled;

        if (!wanted) {
            if (existing) { existing.remove(); }
            return;
        }

        if (existing) { return; }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'select-clear';
        button.setAttribute('data-select-clear', '');
        button.setAttribute('aria-label', 'Clear selection');
        button.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" ' +
            'stroke-linecap="round" aria-hidden="true">' +
            '<line x1="18" y1="6" x2="6" y2="18"></line>' +
            '<line x1="6" y1="6" x2="18" y2="18"></line></svg>';

        this.shell.appendChild(button);
    };

    Select.prototype.matches = function (option, needle) {
        return option.label.toLowerCase().indexOf(needle) !== -1 ||
            option.value.toLowerCase().indexOf(needle) !== -1 ||
            option.group.toLowerCase().indexOf(needle) !== -1;
    };

    Select.prototype.render = function () {
        var self = this;
        var needle = (this.search.value || '').trim().toLowerCase();

        // An empty-string option is the placeholder row ("All categories"),
        // not a result. It is always shown when unfiltered so the trigger can
        // be emptied, and hidden once someone starts typing.
        this.filtered = this.options.filter(function (option) {
            if (option.placeholder) { return needle === ''; }
            return needle === '' || self.matches(option, needle);
        });

        if (this.filtered.length === 0) {
            this.list.innerHTML = '<li class="select-empty">' + escapeHtml(this.emptyText) + '</li>';
            this.active = -1;
            return;
        }

        var selected = this.selectedOption();
        var selectedValue = selected ? selected.value : null;
        var html = '';
        var lastGroup = null;

        this.filtered.forEach(function (option, index) {
            if (option.group && option.group !== lastGroup) {
                html += '<li class="select-group-label" role="presentation">' +
                    escapeHtml(option.group) + '</li>';
                lastGroup = option.group;
            }

            var isSelected = option.value === selectedValue;

            html +=
                '<li class="select-option' + (isSelected ? ' is-selected' : '') + '"' +
                ' role="option"' +
                ' id="' + escapeHtml(this.trigger.id) + '-option-' + index + '"' +
                ' data-index="' + index + '"' +
                ' data-value="' + escapeHtml(option.value) + '"' +
                ' aria-selected="' + (isSelected ? 'true' : 'false') + '"' +
                (option.disabled ? ' aria-disabled="true"' : '') + '>' +
                    '<span class="select-option-label">' + escapeHtml(option.label) + '</span>' +
                    (isSelected
                        ? '<svg class="select-option-check" viewBox="0 0 24 24" fill="none" ' +
                          'stroke="currentColor" stroke-width="2.5" stroke-linecap="round" ' +
                          'stroke-linejoin="round" aria-hidden="true">' +
                          '<polyline points="20 6 9 17 4 12"></polyline></svg>'
                        : '') +
                '</li>';
        }, this);

        this.list.innerHTML = html;

        // Start on the selected option so Enter after opening re-picks what is
        // already chosen instead of jumping to the top of the list.
        var start = this.filtered.findIndex(function (option) {
            return option.value === selectedValue;
        });
        this.active = start === -1 ? this.firstEnabled() : start;
        this.paint();
    };

    Select.prototype.firstEnabled = function () {
        for (var i = 0; i < this.filtered.length; i++) {
            if (!this.filtered[i].disabled) { return i; }
        }
        return -1;
    };

    Select.prototype.paint = function () {
        var self = this;
        var nodes = this.list.querySelectorAll('.select-option');

        Array.prototype.forEach.call(nodes, function (node, index) {
            var isActive = Number(node.getAttribute('data-index')) === self.active;
            node.classList.toggle('is-active', isActive);
            if (isActive) {
                self.search.setAttribute('aria-activedescendant', node.id);
                node.scrollIntoView({ block: 'nearest' });
            }
        });

        if (this.active === -1) { this.search.removeAttribute('aria-activedescendant'); }
    };

    Select.prototype.move = function (delta) {
        if (this.filtered.length === 0) { return; }

        var next = this.active;
        var guard = 0;

        // Skip over disabled options rather than stopping on them, but do not
        // loop forever when a whole list is disabled.
        do {
            next = Math.min(this.filtered.length - 1, Math.max(0, next + delta));
            guard++;
        } while (this.filtered[next] && this.filtered[next].disabled && guard <= this.filtered.length);

        if (this.filtered[next] && !this.filtered[next].disabled) {
            this.active = next;
            this.paint();
        }
    };

    Select.prototype.commit = function (index) {
        var option = this.filtered[index];
        if (!option || option.disabled) { return; }

        this.native.value = option.value;
        this.native.dispatchEvent(new Event('change', { bubbles: true }));

        this.syncLabel();
        this.close();

        // Some selects are navigation, not input: the POS warehouse picker
        // reloads the terminal against the chosen warehouse. Driving that from
        // the component rather than an inline onchange means an injected or
        // re-rendered select behaves the same as a server-rendered one.
        if (this.native.hasAttribute('data-select-submit') && this.native.form) {
            this.native.form.submit();
        }
    };

    Select.prototype.show = function () {
        if (this.open || this.native.disabled) { return; }

        closeOthers(this);

        this.readOptions();
        this.search.value = '';
        this.search.placeholder = this.options.length > 9 ? this.searchPlaceholder : '';

        // Flip above the trigger when the panel would run off the bottom. This
        // is the case that matters: a field at the foot of a modal has nowhere
        // to go, and a listbox that renders downwards is simply unreachable.
        var rect = this.trigger.getBoundingClientRect();
        var needed = Math.min(this.options.length, 8) * 34 + 56;
        this.root.classList.toggle('is-dropup', window.innerHeight - rect.bottom < needed && rect.top > needed);

        this.panel.hidden = false;
        this.root.classList.add('is-open');
        this.trigger.setAttribute('aria-expanded', 'true');
        this.open = true;

        // Registration has to happen here, not in enhance(): OPEN is what the
        // document-level click and Escape handlers consult, and a field that
        // opened without ever appearing in it stayed open until something else
        // closed it by accident.
        if (OPEN.indexOf(this) === -1) { OPEN.push(this); }

        this.render();

        if (this.search.placeholder) {
            requestAnimationFrame(function () { this.search.focus(); }.bind(this));
        }
    };

    Select.prototype.close = function () {
        if (!this.open) { return; }

        this.panel.hidden = true;
        this.root.classList.remove('is-open', 'is-dropup');
        this.trigger.setAttribute('aria-expanded', 'false');
        this.open = false;

        var index = OPEN.indexOf(this);
        if (index !== -1) { OPEN.splice(index, 1); }
    };

    Select.prototype.toggle = function () {
        this.open ? this.close() : this.show();
    };

    /* ---- Validation --------------------------------------------------------

       The native control is `required` but cannot be focused, so the browser's
       own "not focusable" behaviour would silently block the submit with no
       message. Validity is therefore checked here, and reported in the same
       place the server would have reported it. */
    Select.prototype.validate = function () {
        if (!this.required) { return true; }

        var option = this.selectedOption();
        var valid = !!option && !option.placeholder;

        this.root.classList.toggle('is-invalid', !valid);
        this.native.setAttribute('data-invalid', valid ? 'false' : 'true');

        var message = this.root.parentElement.querySelector('.select-error');
        var label = this.trigger.getAttribute('aria-labelledby');
        var text = this.trigger.textContent.trim();

        if (valid) {
            if (message) { message.remove(); }
        } else {
            var fallback = (label && byId(label) ? byId(label).textContent.replace('*', '').trim() : text);
            var html = '<div class="select-error">' + escapeHtml(fallback + ' is required.') + '</div>';
            if (message) { message.innerHTML = html.replace(/^<div class="select-error">|<\/div>$/g, ''); }
            else { this.root.parentElement.insertAdjacentHTML('beforeend', html); }
        }

        return valid;
    };

    Select.prototype.enhance = function () {
        var self = this;

        this.root.classList.add('is-enhanced');
        this.readOptions();
        this.syncLabel();

        this.trigger.addEventListener('click', function (event) {
            event.preventDefault();
            self.toggle();
        });

        this.trigger.addEventListener('keydown', function (event) {
            switch (event.key) {
                case 'ArrowDown':
                case 'ArrowUp':
                case 'Enter':
                case ' ':
                    event.preventDefault();
                    self.show();
                    break;
                case 'Home':
                    if (self.open) { event.preventDefault(); self.active = self.firstEnabled(); self.paint(); }
                    break;
                case 'End':
                    if (self.open) {
                        event.preventDefault();
                        self.active = self.filtered.length - 1;
                        self.paint();
                    }
                    break;
                case 'Escape':
                    if (self.open) { event.preventDefault(); self.close(); self.trigger.focus(); }
                    break;
                case 'Tab':
                    self.close();
                    break;
                default:
                    // Type-ahead on the closed trigger: jump to a matching
                    // option rather than doing nothing at all.
                    if (!self.open && event.key.length === 1 && !event.ctrlKey && !event.metaKey) {
                        var needle = event.key.toLowerCase();
                        var hit = self.options.find(function (option) {
                            return !option.placeholder && !option.disabled &&
                                option.label.toLowerCase().indexOf(needle) === 0;
                        });
                        if (hit) {
                            event.preventDefault();
                            self.native.value = hit.value;
                            self.native.dispatchEvent(new Event('change', { bubbles: true }));
                            self.syncLabel();
                        }
                    }
            }
        });

        this.root.addEventListener('click', function (event) {
            var clear = event.target.closest('[data-select-clear]');
            if (clear) {
                event.preventDefault();
                event.stopPropagation();
                self.native.value = '';
                self.native.dispatchEvent(new Event('change', { bubbles: true }));
                self.syncLabel();
                self.trigger.focus();
                return;
            }

            var node = event.target.closest('.select-option');
            if (node) {
                event.preventDefault();
                self.commit(Number(node.getAttribute('data-index')));
            }
        });

        this.search.addEventListener('input', function () { self.render(); });

        this.search.addEventListener('keydown', function (event) {
            switch (event.key) {
                case 'ArrowDown': event.preventDefault(); self.move(1); break;
                case 'ArrowUp': event.preventDefault(); self.move(-1); break;
                case 'Home': event.preventDefault(); self.active = self.firstEnabled(); self.paint(); break;
                case 'End':
                    event.preventDefault();
                    self.active = self.filtered.length - 1;
                    self.paint();
                    break;
                case 'Enter': event.preventDefault(); self.commit(self.active); break;
                case 'Escape': event.preventDefault(); self.close(); self.trigger.focus(); break;
                case 'Tab': self.close(); break;
            }
        });

        this.root.addEventListener('focusin', function () { self.root.classList.add('is-focus'); });
        this.root.addEventListener('focusout', function () { self.root.classList.remove('is-focus'); });

        // Anything that rewrites the native element from the outside has to
        // refresh the list, because the native element is the source of truth.
        this.native.addEventListener('change', function () {
            self.syncLabel();
            if (self.open) { self.render(); }
        });
    };

    /* ---- Wiring ------------------------------------------------------------ */

    function closeOthers(except) {
        OPEN.slice().forEach(function (instance) {
            if (instance !== except) { instance.close(); }
        });
    }

    App.selects = {
        instances: [],

        /** Enhance every un-enhanced select inside a root (defaults to the document). */
        init(root) {
            var scope = root || document;

            // A container re-rendered by innerHTML leaves the instances built
            // over its previous contents pointing at detached nodes. They keep
            // validating on submit and keep handling clicks for a UI that no
            // longer exists, so they are dropped here rather than left to pile
            // up. Any instance still attached to the document is kept, which is
            // what makes calling init() on a sub-tree safe.
            App.selects.instances = App.selects.instances.filter(function (instance) {
                return instance.root.isConnected;
            });

            OPEN.slice().forEach(function (instance) {
                if (!instance.root.isConnected) { instance.close(); }
            });

            var nodes = scope.querySelectorAll('[data-select]:not(.is-enhanced)');

            Array.prototype.forEach.call(nodes, function (node) {
                var instance = new Select(node);
                instance.enhance();
                App.selects.instances.push(instance);
            });

            return App.selects;
        },

        /** Drop an instance so the next init() rebuilds it from fresh markup. */
        refresh(instance) {
            var index = App.selects.instances.indexOf(instance);
            if (index !== -1) { App.selects.instances.splice(index, 1); }
            instance.close();
        },

        /** All instances inside a container — used by page scripts after they inject rows. */
        within(container) {
            return App.selects.instances.filter(function (instance) {
                return container.contains(instance.root);
            });
        }
    };

    document.addEventListener('click', function (event) {
        if (OPEN.length === 0) { return; }
        if (event.target.closest('[data-select]')) { return; }
        closeOthers(null);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || OPEN.length === 0) { return; }
        var top = OPEN[OPEN.length - 1];
        top.close();
        top.trigger.focus();
    }, true);

    /* Required fields are checked here because the native control is invisible
       and the browser cannot focus it to report its own failure. */
    document.addEventListener('submit', function (event) {
        var invalid = [];

        App.selects.instances.forEach(function (instance) {
            // A container re-rendered between renders and submit leaves
            // instances behind on detached nodes; validating those would block
            // a form over a field the cashier cannot even see.
            if (!instance.root.isConnected) { return; }

            if (!instance.validate()) { invalid.push(instance); }
        });

        if (invalid.length > 0) {
            event.preventDefault();
            invalid[invalid.length - 1].trigger.focus();
        }
    }, true);

    App.initSelects = function () { return App.selects.init(); };

    if (document.readyState !== 'loading') { App.selects.init(); }
    else { document.addEventListener('DOMContentLoaded', function () { App.selects.init(); }); }
})();
