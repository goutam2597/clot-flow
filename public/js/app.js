/* ==========================================================================
   Meridian POS — Core Application JS (vanilla)
   Theme, sidebar, dropdowns, modals, toasts, CSRF helpers.
   ========================================================================== */
(function () {
    'use strict';

    var App = window.Meridian = window.Meridian || {};

    /* Localised UI strings that live in no Blade view. Defaults only: this
       file is static, so it cannot carry Blade syntax - a view that needs
       localised strings overrides Meridian.labels in its own script block. */
    App.labels = {
        unsavedSingular: 'unsaved change',
        unsavedPlural: 'unsaved changes',
        settingsSaved: 'Settings saved successfully.'
    };

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ---- Theme (light/dark) with persistence ---- */
    App.theme = {
        get() {
            return document.documentElement.getAttribute('data-theme') || 'light';
        },
        set(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            try { localStorage.setItem('meridian-theme', theme); } catch (e) {}
        },
        toggle() {
            this.set(this.get() === 'dark' ? 'light' : 'dark');
        },
        init() {
            var stored = null;
            try { stored = localStorage.getItem('meridian-theme'); } catch (e) {}
            var current = document.documentElement.getAttribute('data-theme');
            if (!current) {
                this.set(stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
            }
        }
    };

    /* ---- Sidebar toggle ---- */
    App.sidebar = {
        toggle() { document.getElementById('app') && document.getElementById('app').classList.toggle('sidebar-collapsed'); },
        openMobile() { document.getElementById('app') && document.getElementById('app').classList.add('sidebar-open'); },
        closeMobile() { document.getElementById('app') && document.getElementById('app').classList.remove('sidebar-open'); }
    };

    /* ---- Dropdowns (data-dropdown-toggle) ---- */
    function closeAllDropdowns(except) {
        document.querySelectorAll('.dropdown-menu.open').forEach(function (menu) {
            if (menu !== except) {
                menu.classList.remove('open');

                // The trigger's open fill keys off this, so reset it with the
                // menu - otherwise a dismissed menu leaves its button lit.
                var trigger = menu.parentElement.querySelector('[data-dropdown-toggle]');
                if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
            }
        });
    }
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-dropdown-toggle]');
        if (trigger) {
            e.preventDefault();
            var menu = trigger.parentElement.querySelector('.dropdown-menu');
            if (menu) {
                var willOpen = !menu.classList.contains('open');
                closeAllDropdowns(menu);
                menu.classList.toggle('open', willOpen);
                trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            }
            return;
        }
        if (!e.target.closest('.dropdown-menu')) { closeAllDropdowns(); }
    });

    /* ---- Modals (data-modal-open="#id" / [data-modal-close]) ---- */
    App.modal = {
        open(selector) {
            var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
            if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
        },
        close(selector) {
            var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
            if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
        }
    };
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-modal-open]');
        if (opener) { e.preventDefault(); App.modal.open(opener.getAttribute('data-modal-open')); return; }
        var closer = e.target.closest('[data-modal-close]');
        if (closer) {
            e.preventDefault();
            var backdrop = closer.closest('.modal-backdrop');
            if (backdrop) { App.modal.close('#' + backdrop.id); }
            return;
        }
        if (e.target.classList && e.target.classList.contains('modal-backdrop')) { App.modal.close('#' + e.target.id); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllDropdowns();
            document.querySelectorAll('.modal-backdrop.open').forEach(function (m) { m.classList.remove('open'); });
            document.body.style.overflow = '';
        }
    });

    /* ---- Toast notifications ----

       One card per event: icon, optional title, message, close button, and a
       progress hairline that shows the time left. The auto-dismiss rides on
       the progress animation's own end event, so pausing (hover) is free —
       the bar and the countdown can never disagree. Failures carry no bar at
       all: an error leaves only when it is dismissed, never on a timer. */
    App.toastIcons = {
        success: '<polyline points="20 6 9 17 4 12"/>',
        danger: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        warning: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        info: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>'
    };

    App.toast = function (message, type, title) {
        type = type || 'info';

        var container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
        }

        // Past four cards the oldest goes, unread or not — a column filling
        // the screen is a log file, not a notification.
        while (container.children.length >= 4) {
            container.firstElementChild.remove();
        }

        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type + (type === 'danger' ? ' toast-persistent' : '');
        toast.setAttribute('role', type === 'danger' ? 'alert' : 'status');

        var icon = App.toastIcons[type] || App.toastIcons.info;
        toast.innerHTML =
            '<span class="toast-icon" aria-hidden="true">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + icon + '</svg>' +
            '</span>' +
            '<div class="toast-body">' +
                (title ? '<div class="toast-title">' + escapeHtml(title) + '</div>' : '') +
                '<div class="toast-msg">' + escapeHtml(message) + '</div>' +
            '</div>' +
            '<button type="button" class="toast-close" aria-label="Dismiss">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
            '</button>' +
            '<span class="toast-progress" aria-hidden="true"></span>';

        function dismiss() {
            if (!toast.isConnected) { return; }
            toast.classList.add('toast-out');
            toast.addEventListener('animationend', function () { toast.remove(); }, { once: true });
        }

        toast.querySelector('.toast-close').addEventListener('click', dismiss);

        // The progress hairline is a timed animation; when it finishes the
        // card goes. Hovering pauses the bar, so it also pauses the exit.
        var progress = toast.querySelector('.toast-progress');
        if (progress) {
            progress.style.animationDuration = '4800ms';
            progress.addEventListener('animationend', dismiss);
        }

        container.appendChild(toast);
        return toast;
    };

    /* ---- Row action tooltips ----

       Icon-only table buttons carry their meaning in aria-label. Copy it to
       the native title once at startup, so every table in the app gets hover
       labels without each view remembering to pass both attributes. */
    function wireRowActionTooltips(root) {
        root.querySelectorAll('.table-actions-btn[aria-label]:not([title])').forEach(function (btn) {
            btn.title = btn.getAttribute('aria-label');
        });
    }
    wireRowActionTooltips(document);

    App.csrf = function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    /* ---- Prevent duplicate financial/inventory submissions ---- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.hasAttribute('data-prevent-double') && !form.dataset.submitted) {
            form.dataset.submitted = '1';
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.classList.add('is-loading'); btn.disabled = true; }
        }
    });

    /* ---- Theme + sidebar toggle buttons ---- */
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-theme-toggle]')) { App.theme.toggle(); }
        if (e.target.closest('[data-sidebar-toggle]')) {
            var app = document.getElementById('app');
            if (!app) { return; }
            if (window.innerWidth <= 1024) { app.classList.toggle('sidebar-open'); }
            else { app.classList.toggle('sidebar-collapsed'); }
        }
    });

    /* ---- Indeterminate checkboxes ----

       `indeterminate` is a DOM property with no HTML attribute behind it, so
       CSS cannot match it. Without this mirror, a "select all" box in its
       partial state paints as completely empty — which reads as "nothing is
       selected" and is the opposite of what it means. */
    function syncIndeterminate(box) {
        box.setAttribute('data-indeterminate', box.indeterminate ? 'true' : 'false');
    }

    document.addEventListener('change', function (e) {
        var box = e.target;
        if (box && box.type === 'checkbox') { syncIndeterminate(box); }
    }, true);

    App.init = function () {
        App.theme.init();

        document.querySelectorAll('input[type="checkbox"][indeterminate]').forEach(syncIndeterminate);

        // Enhances every custom select on the page. select.js owns the
        // behaviour; calling init from here means a page that loads app.js
        // without select.js simply renders native selects, which is correct.
        if (App.selects && typeof App.selects.init === 'function') { App.selects.init(); }
    };
    if (document.readyState !== 'loading') { App.init(); }
    else { document.addEventListener('DOMContentLoaded', App.init); }
})();

