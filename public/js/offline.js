/* ==========================================================================
   Meridian POS — Offline POS: IndexedDB queue & synchronization

   What this does and does not guarantee
   --------------------------------------
   DOES:  keep selling while the connection is down. Every sale is written to
          IndexedDB with a stable idempotency key, survives a refresh or a crash,
          and is pushed to the server the moment connectivity returns. Retries
          are safe: the server returns the original sale for a key it has
          already seen, so a duplicate can never be created.

   DOES NOT: give you authoritative stock. A disconnected terminal is working
          from the catalog it last saw. Stock conflicts are therefore recorded
          and surfaced for a human instead of being silently overwritten — see
          `flagConflict`.

   Payments taken offline are recorded locally and are marked as pending
   reconciliation. Nothing here contacts a payment gateway.
   ========================================================================== */
(function () {
    'use strict';

    var App = window.Meridian || (window.Meridian = {});

    var DB_NAME = 'meridian-pos';
    var DB_VERSION = 1;
    var STORE_SALES = 'pending_sales';
    var STORE_CATALOG = 'catalog';
    var STORE_META = 'meta';

    var cfg = {
        enabled: false,
        syncUrl: '/sync/sales',
        csrf: '',
        retryLimit: 8,
        backoffMs: [1000, 4000, 15000, 60000, 300000]
    };

    var dbPromise = null;

    /* ======================================================================
       IndexedDB plumbing
       ====================================================================== */

    function openDb() {
        if (dbPromise) { return dbPromise; }

        dbPromise = new Promise(function (resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB is not available in this browser.'));
                return;
            }

            var request = window.indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function (event) {
                var db = event.target.result;

                if (!db.objectStoreNames.contains(STORE_SALES)) {
                    var store = db.createObjectStore(STORE_SALES, { keyPath: 'key' });
                    store.createIndex('status', 'status');
                    store.createIndex('created_at', 'created_at');
                }
                if (!db.objectStoreNames.contains(STORE_CATALOG)) {
                    db.createObjectStore(STORE_CATALOG, { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains(STORE_META)) {
                    db.createObjectStore(STORE_META, { keyPath: 'key' });
                }
            };

            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });

        return dbPromise;
    }

    function tx(storeName, mode, work) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var transaction = db.transaction(storeName, mode);
                var store = transaction.objectStore(storeName);
                var result;

                try {
                    result = work(store);
                } catch (e) {
                    reject(e);
                    return;
                }

                transaction.oncomplete = function () { resolve(result && result.result !== undefined ? result.result : result); };
                transaction.onerror = function () { reject(transaction.error); };
                transaction.onabort = function () { reject(transaction.error); };
            });
        });
    }

    function getAll(storeName) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var request = db.transaction(storeName, 'readonly').objectStore(storeName).getAll();
                request.onsuccess = function () { resolve(request.result || []); };
                request.onerror = function () { reject(request.error); };
            });
        });
    }

    /* ======================================================================
       Connectivity
       ====================================================================== */

    function isOffline() {
        return navigator.onLine === false;
    }

    /* ======================================================================
       Offline sales queue
       ====================================================================== */

    function newKey() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        return 'off-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12);
    }

    /**
     * Persist a sale locally. The key is the idempotency key the server will
     * see, so pushing twice is indistinguishable from pushing once.
     */
    function enqueueSale(payload) {
        if (!cfg.enabled) {
            return Promise.reject(new Error('Offline mode is disabled for this business.'));
        }

        var record = {
            key: payload.idempotency_key || newKey(),
            payload: payload,
            status: 'pending',
            attempts: 0,
            created_at: new Date().toISOString(),
            last_error: null,
            invoice_reference: null
        };

        return tx(STORE_SALES, 'readwrite', function (store) {
            return store.put(record);
        }).then(function () {
            broadcastCount();
            scheduleSync();
            return record.key;
        });
    }

    function updateRecord(key, changes) {
        return getRecord(key).then(function (record) {
            if (!record) { return null; }
            Object.keys(changes).forEach(function (field) {
                record[field] = changes[field];
            });
            return tx(STORE_SALES, 'readwrite', function (store) {
                return store.put(record);
            }).then(function () { return record; });
        });
    }

    function getRecord(key) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var request = db.transaction(STORE_SALES, 'readonly').objectStore(STORE_SALES).get(key);
                request.onsuccess = function () { resolve(request.result || null); };
                request.onerror = function () { reject(request.error); };
            });
        });
    }

    function removeRecord(key) {
        return tx(STORE_SALES, 'readwrite', function (store) {
            return store.delete(key);
        }).then(broadcastCount);
    }

    function pending() {
        return getAll(STORE_SALES).then(function (rows) {
            return rows.filter(function (row) {
                return row.status === 'pending' || row.status === 'failed';
            });
        });
    }

    function counts() {
        return getAll(STORE_SALES).then(function (rows) {
            var summary = { total: rows.length, pending: 0, syncing: 0, failed: 0, synced: 0, attention: 0 };

            rows.forEach(function (row) {
                if (summary[row.status] !== undefined) { summary[row.status] += 1; }
            });

            summary.queue = summary.pending + summary.syncing + summary.failed + summary.attention;
            return summary;
        });
    }

    /* ======================================================================
       Catalog cache — lets the terminal keep selling from a known catalog
       ====================================================================== */

    function cacheCatalog(products) {
        if (!cfg.enabled || !Array.isArray(products) || !products.length) {
            return Promise.resolve(0);
        }

        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var transaction = db.transaction(STORE_CATALOG, 'readwrite');
                var store = transaction.objectStore(STORE_CATALOG);
                var saved = 0;

                products.forEach(function (product) {
                    store.put(product);
                    saved += 1;
                });

                tx(STORE_META, 'readwrite', function (meta) {
                    return meta.put({ key: 'catalog_synced_at', value: new Date().toISOString() });
                });

                transaction.oncomplete = function () { resolve(saved); };
                transaction.onerror = function () { reject(transaction.error); };
            });
        });
    }

    function cachedCatalog() {
        return getAll(STORE_CATALOG).catch(function () { return []; });
    }

    /* ======================================================================
       Conflict detection
       ----------------------------------------------------------------------
       A queued sale carries the stock level the terminal believed was
       available. When the server pushes it, the authoritative check happens
       server-side; here we record what the terminal thought, so an operator
       can see exactly where the two disagreed. We never auto-resolve.
       ====================================================================== */

    function flagConflict(key, expected, actual) {
        return updateRecord(key, {
            status: 'attention',
            conflict: { expected: expected, actual: actual, at: new Date().toISOString() }
        });
    }

    /* ======================================================================
       Synchronization
       ====================================================================== */

    var syncing = false;
    var timer = null;

    function scheduleSync(delay) {
        if (!cfg.enabled) { return; }
        window.clearTimeout(timer);
        timer = window.setTimeout(sync, delay === undefined ? 1500 : delay);
    }

    function sync(manual) {
        if (!cfg.enabled || syncing || isOffline()) { return Promise.resolve(null); }

        return pending().then(function (rows) {
            if (!rows.length) {
                if (manual) { App.toast('Nothing to synchronize.', 'info'); }
                return null;
            }

            syncing = true;
            setStatus('syncing', rows.length);

            var batch = rows.slice(0, 50);

            return Promise.all(batch.map(function (row) {
                return updateRecord(row.key, { status: 'syncing' });
            })).then(function () {
                return pushBatch(batch);
            }).then(function (report) {
                syncing = false;
                broadcastCount();

                if (report && report.summary) {
                    if (manual) { App.toast(summarise(report.summary), report.summary.failed ? 'warning' : 'success'); }
                }

                // Keep draining if the queue is still deep.
                scheduleSync(2000);
                return report;
            }).catch(function (error) {
                syncing = false;
                broadcastCount();
                if (manual) { App.toast(error.message || 'Synchronization failed.', 'danger'); }
                return null;
            });
        });
    }

    function pushBatch(batch) {
        // One request per batch rather than per record: the server processes
        // each sale in its own transaction and replies per record, so a single
        // malformed sale still leaves the rest synchronized.
        return fetch(cfg.syncUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': cfg.csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                sales: batch.map(function (row) { return row.payload; })
            })
        })
            .then(function (response) { return response.json(); })
            .then(function (body) {
                var results = body.results || [];

                return Promise.all(results.map(function (result) {
                    if (result.result === 'synced' || result.result === 'skipped') {
                        return removeRecord(result.key);
                    }

                    return updateRecord(result.key, {
                        status: result.result === 'attention' ? 'attention' : 'failed',
                        attempts: 0,
                        last_error: result.message || 'The server rejected this sale.'
                    });
                })).then(function () { return body; });
            });
    }

    function summarise(summary) {
        return 'Sync: ' + (summary.synced || 0) + ' synchronized, ' +
            (summary.failed || 0) + ' failed, ' +
            (summary.attention || 0) + ' need attention.';
    }

    /* ======================================================================
       UI status
       ====================================================================== */

    function setStatus(state, count) {
        var node = document.getElementById('pos-sync-status');
        if (!node) { return; }

        node.className = 'badge badge-' + (
            state === 'offline' ? 'warning' : state === 'syncing' ? 'info' : 'success'
        );
        node.textContent = state === 'offline'
            ? 'Offline' + (count ? ' · ' + count + ' queued' : '')
            : (state === 'syncing' ? 'Syncing ' + count + '…' : 'Online');
        node.hidden = false;
    }

    function broadcastCount() {
        counts().then(function (summary) {
            var node = document.getElementById('pos-sync-count');
            if (node) {
                node.textContent = summary.queue > 0 ? String(summary.queue) : '';
                node.hidden = summary.queue === 0;
            }

            if (summary.failed > 0 || summary.attention > 0) {
                App.toast(
                    summary.failed + ' offline sale(s) failed to sync and need attention.',
                    'danger'
                );
            }

            window.dispatchEvent(new CustomEvent('meridian:sync', { detail: summary }));
        });
    }

    /* ======================================================================
       Lifecycle
       ====================================================================== */

    function init(options) {
        Object.keys(options || {}).forEach(function (key) {
            if (options[key] !== undefined && options[key] !== null) { cfg[key] = options[key]; }
        });

        if (!cfg.enabled) { return; }

        window.addEventListener('online', function () {
            setStatus('syncing', 0);
            scheduleSync(250);
        });

        window.addEventListener('offline', function () {
            setStatus('offline', 0);
        });

        setStatus(isOffline() ? 'offline' : 'syncing', 0);
        scheduleSync(2500);
    }

    App.offline = {
        init: init,
        isOffline: isOffline,
        enqueueSale: enqueueSale,
        cacheCatalog: cacheCatalog,
        cachedCatalog: cachedCatalog,
        pending: pending,
        counts: counts,
        sync: sync,
        flagConflict: flagConflict,
        removeRecord: removeRecord,
        config: function () { return cfg; }
    };
})();
