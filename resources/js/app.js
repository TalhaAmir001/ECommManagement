import './bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('dashboard-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const closeBtn = document.getElementById('sidebar-close');

    const openSidebar = () => {
        sidebar?.classList.remove('-translate-x-full');
        sidebar?.classList.add('translate-x-0');
        backdrop?.classList.remove('hidden');
    };

    const closeSidebar = () => {
        sidebar?.classList.add('-translate-x-full');
        sidebar?.classList.remove('translate-x-0');
        backdrop?.classList.add('hidden');
    };

    closeBtn?.addEventListener('click', closeSidebar);
    backdrop?.addEventListener('click', closeSidebar);

    // Expose openSidebar so any future trigger (e.g. a sidebar-header
    // hamburger added back on mobile) can call it.
    window.__openDashboardSidebar = openSidebar;

    // --- User menu dropdown (sidebar footer) ---------------------------
    // One trigger/panel pair per page. Click toggles; outside-click and
    // Escape close. Anchored to the trigger via aria-controls so future
    // menus can be added without touching this block.
    document.querySelectorAll('[data-user-menu-trigger]').forEach((trigger) => {
        const panelId = trigger.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;
        if (!panel) return;

        const open = () => {
            trigger.setAttribute('aria-expanded', 'true');
            panel.classList.remove('hidden');
        };
        const close = () => {
            trigger.setAttribute('aria-expanded', 'false');
            panel.classList.add('hidden');
        };
        const isOpen = () => trigger.getAttribute('aria-expanded') === 'true';

        trigger.addEventListener('click', (event) => {
            event.stopPropagation();
            if (isOpen()) close();
            else open();
        });

        document.addEventListener('click', (event) => {
            if (!isOpen()) return;
            if (trigger.contains(event.target) || panel.contains(event.target)) return;
            close();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && isOpen()) {
                close();
                trigger.focus();
            }
        });
    });
});

(function () {
    const anchor = document.getElementById('orders-poll-anchor');
    if (!anchor) return;

    const tbody = document.getElementById('orders-tbody');
    if (!tbody) return;

    const pollUrl = anchor.dataset.pollUrl;
    const rowsUrl = anchor.dataset.rowsUrl;
    let intervalId = null;
    const POLL_INTERVAL = 8000;

    function flashRow(row) {
        row.classList.add('row-flash');
        setTimeout(() => {
            row.classList.remove('row-flash');
        }, 1500);
    }

    function findExistingRow(shopifyId) {
        return tbody.querySelector(`tr[data-shopify-id="${shopifyId}"]`);
    }

    function applyRows(rows) {
        if (!rows || rows.length === 0) return;

        const emptyTd = tbody.querySelector('td[colspan="8"]');
        if (emptyTd) {
            const emptyRow = emptyTd.closest('tr');
            if (emptyRow) emptyRow.remove();
        }

        rows.forEach(({ shopify_id, html }) => {
            const key = shopify_id || '';
            const existing = key ? findExistingRow(key) : null;

            const temp = document.createElement('tbody');
            temp.innerHTML = html;
            const newRow = temp.firstElementChild;

            if (existing) {
                existing.outerHTML = newRow.outerHTML;
                const updated = tbody.querySelector(`tr[data-shopify-id="${key}"]`) ||
                    (key === '' ? tbody.querySelector(`tr[data-order-id="${newRow.dataset.orderId}"]`) : null);
                if (updated) flashRow(updated);
            } else {
                newRow.classList.add('row-flash');
                tbody.insertBefore(newRow, tbody.firstChild);
                setTimeout(() => {
                    newRow.classList.remove('row-flash');
                }, 1500);
            }
        });
    }

    // The active filters (q, payment, fulfillment, status, date, …) are
    // embedded on the anchor so live-poll requests stay scoped to the
    // current view. Without this, /orders/rows would keep injecting orders
    // that don't belong in a search/filtered table.
    function readPollFilters() {
        try {
            return JSON.parse(anchor.dataset.filters || '{}');
        } catch (err) {
            return {};
        }
    }

    function buildPollQuery(extra) {
        const params = Object.assign({}, readPollFilters(), extra);
        return Object.keys(params)
            .filter((key) => params[key] !== '' && params[key] !== null && params[key] !== undefined)
            .map((key) => encodeURIComponent(key) + '=' + encodeURIComponent(params[key]))
            .join('&');
    }

    async function poll() {
        const since = anchor.dataset.since || '';
        const query = buildPollQuery({ since });
        const url = pollUrl + (query !== '' ? '?' + query : '');

        try {
            const res = await axios.get(url);
            const data = res.data;

            if (!data.changed) return;

            const rowsRes = await axios.get(rowsUrl + '?' + query);
            applyRows(rowsRes.data.rows);

            if (rowsRes.data.latest_updated_at) {
                anchor.dataset.since = rowsRes.data.latest_updated_at;
            }
        } catch (err) {
            // Silently ignore network errors to avoid console noise during polling.
        }
    }

    function startPolling() {
        if (intervalId) return;
        intervalId = setInterval(poll, POLL_INTERVAL);
    }

    function stopPolling() {
        if (intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            stopPolling();
        } else {
            poll();
            startPolling();
        }
    });

    startPolling();
})();

// ---------------------------------------------------------------------------
// Order typeahead (used by the new-shipment form and the shipment-link
// form). Pairs with /shipments/lookup-orders. Renders a small dropdown
// of candidate orders beneath the input; clicking one fills the hidden
// order_id and the visible summary card. Server-side validation is the
// source of truth — this widget just makes the safe choice the easy
// choice.
// ---------------------------------------------------------------------------
(function () {
    const DEBOUNCE_MS = 180;

    function debounce(fn, wait) {
        let timer = null;
        return function (...args) {
            if (timer !== null) clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), wait);
        };
    }

    function formatMoney(value) {
        const n = Number(value || 0);
        return 'PKR ' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function linkStatusBadge(status) {
        // Small label that visually groups results: "linked" (already
        // has a shipment) vs "unlinked" (no shipment yet). Operators
        // can spot unfulfilled orders at a glance.
        if (status === 'linked') {
            return '<span class="inline-flex items-center gap-1 rounded-full bg-accent-soft px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-accent">'
                + '<span class="h-1.5 w-1.5 rounded-full bg-accent"></span>Linked</span>';
        }
        return '<span class="inline-flex items-center gap-1 rounded-full bg-canvas px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted">'
            + '<span class="h-1.5 w-1.5 rounded-full bg-faint"></span>Unlinked</span>';
    }

    function attach(root) {
        const hidden = root.querySelector('[data-order-picker-id]');
        const input = root.querySelector('[data-order-picker-input]');
        const results = root.querySelector('[data-order-picker-results]');
        const summary = root.querySelector('[data-order-picker-summary]');
        if (!hidden || !input || !results || !summary) return;

        // The lookup endpoint URL is rendered by Blade with the right
        // subpath. We can't construct it from `window.location.origin`
        // because the app may be mounted at a subdirectory
        // (e.g. /ECommManagement) and the typeahead would then hit the
        // wrong host path.
        const lookupUrl = root.dataset.lookupUrl
            || (window.location.origin + '/shipments/lookup-orders');

        let activeIndex = -1;
        let currentItems = [];

        // The new-shipment form exposes weight & pieces inputs next to the
        // picker; other pickers (the shipment-link form on the show/row
        // pickers) don't, so these stay null there and this block is a
        // no-op. When an order is picked we pre-fill the derived defaults.
        const shipmentForm = root.closest('form');
        const weightInput = shipmentForm ? shipmentForm.querySelector('[name="weight_kg"]') : null;
        const piecesInput = shipmentForm ? shipmentForm.querySelector('[name="pieces"]') : null;
        const codInput = shipmentForm ? shipmentForm.querySelector('[name="cod_amount"]') : null;

        // Consignee inputs that can be pre-filled from the picked order's
        // shipping address / customer record (only present on the
        // new-shipment form).
        const consigneeInputs = ['consignee_name', 'consignee_phone', 'consignee_email', 'consignee_city', 'consignee_address']
            .map((name) => shipmentForm ? shipmentForm.querySelector('[name="' + name + '"]') : null)
            .filter((el) => el !== null);

        function markManual(input) {
            if (input) input.dataset.autoFilled = '0';
        }
        [weightInput, piecesInput, codInput, ...consigneeInputs]
            .filter((el) => el !== null)
            .forEach((el) => el.addEventListener('input', () => markManual(el)));

        // Fill a blank field — or one we auto-filled from a previously
        // picked order — with a derived value. Anything the operator typed
        // themselves is always respected and never overwritten.
        function prefillDerived(input, value) {
            if (!input) return;
            const isAuto = input.dataset.autoFilled === '1';
            const isEmpty = input.value === null || input.value === '';
            if ((isEmpty || isAuto) && value !== null && value !== undefined && value !== '') {
                input.value = value;
                input.dataset.autoFilled = '1';
            }
        }

        function clearSelection() {
            hidden.value = '';
            summary.innerHTML = '<span class="text-faint">No order selected — the auto-matcher will try to link after creation.</span>';
            summary.classList.remove('border-accent', 'bg-accent-soft');
            // Drop defaults we filled from the previously picked order so a
            // cleared selection doesn't leave stale weight/pieces behind.
            if (weightInput && weightInput.dataset.autoFilled === '1') weightInput.value = '';
            if (piecesInput && piecesInput.dataset.autoFilled === '1') piecesInput.value = '';
            if (codInput && codInput.dataset.autoFilled === '1') codInput.value = '';
        }

        function pickItem(item) {
            hidden.value = item.id;
            summary.innerHTML =
                '<div class="flex flex-wrap items-center gap-2 text-sm text-ink">' +
                    '<span class="font-semibold tabular-nums">' + escapeHtml(item.number) + '</span>' +
                    (item.customer ? '<span class="text-muted">· ' + escapeHtml(item.customer) + '</span>' : '') +
                    (item.city ? '<span class="text-muted">· ' + escapeHtml(item.city) + '</span>' : '') +
                    '<span class="ml-auto flex items-center gap-2 text-xs text-muted">' +
                        (item.link_status ? linkStatusBadge(item.link_status) : '') +
                        '<span class="tabular-nums">' + escapeHtml(formatMoney(item.total)) + '</span>' +
                    '</span>' +
                '</div>' +
                (item.reason ? '<p class="mt-1 text-[11px] text-muted">' + escapeHtml(item.reason) + '</p>' : '');
            summary.classList.add('border-accent', 'bg-accent-soft');
            results.classList.add('hidden');
            currentItems = [];
            activeIndex = -1;

            // Pre-fill the consignee details we can pull from the picked
            // order's customer record (name / phone / email). Blank or
            // previously auto-filled inputs get overwritten; values the
            // operator typed themselves are always respected.
            consigneeInputs.forEach((input) => {
                prefillDerived(input, item[input.getAttribute('name')]);
            });

            // Pre-fill the shipment's derived defaults (total order weight
            // and total item quantity = pieces) into the new-shipment form.
            prefillDerived(weightInput, item.weight_kg);
            prefillDerived(piecesInput, item.pieces && item.pieces > 0 ? item.pieces : null);

            // Pre-fill COD with the order's collectable total when the order
            // is unpaid (a PENDING financial status). Blank or auto-filled
            // inputs get overwritten; operator-typed values are respected.
            prefillDerived(codInput, item.cod_amount);
        }

        function renderResults(items) {
            currentItems = items;
            activeIndex = -1;
            if (items.length === 0) {
                results.classList.add('hidden');
                results.innerHTML = '';
                return;
            }
            // Group results: unlinked first (these are the orders the
            // operator probably wants to ship), then linked. Within
            // each group, keep the server-supplied ordering (typically
            // newest-first).
            const unlinked = items.filter(function (i) { return (i.link_status || 'unlinked') === 'unlinked'; });
            const linked = items.filter(function (i) { return i.link_status === 'linked'; });
            const ordered = unlinked.concat(linked);
            currentItems = ordered;

            let html = '';
            if (unlinked.length > 0) {
                html += '<li class="border-b border-line bg-canvas/40 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted">'
                    + '<span class="inline-flex items-center gap-1.5">'
                    +   '<span class="h-1.5 w-1.5 rounded-full bg-faint"></span>Unlinked'
                    + '</span>'
                    + '<span class="float-right tabular-nums">' + unlinked.length + '</span>'
                    + '</li>';
            }
            ordered.forEach(function (item, i) {
                html += (
                    '<li role="option" data-index="' + i + '" class="flex cursor-pointer flex-wrap items-center gap-2 px-3 py-2 text-sm hover:bg-canvas">' +
                        '<span class="font-semibold tabular-nums text-ink">' + escapeHtml(item.number) + '</span>' +
                        (item.customer ? '<span class="text-muted">· ' + escapeHtml(item.customer) + '</span>' : '') +
                        (item.city ? '<span class="text-muted">· ' + escapeHtml(item.city) + '</span>' : '') +
                        '<span class="ml-auto flex items-center gap-2 text-xs text-muted">' +
                            (item.link_status ? linkStatusBadge(item.link_status) : '') +
                            '<span class="tabular-nums">' + escapeHtml(formatMoney(item.total)) + '</span>' +
                        '</span>' +
                        (item.reason ? '<span class="basis-full text-[11px] text-muted">' + escapeHtml(item.reason) + '</span>' : '') +
                    '</li>'
                );
            });
            results.innerHTML = html;
            results.classList.remove('hidden');
        }

        const doSearch = debounce(async function () {
            const query = input.value.trim();
            const phoneField = root.querySelector('[data-order-picker-phone]');
            const params = {};
            if (query !== '') {
                params.q = query;
            } else {
                if (phoneField && phoneField.value.trim() !== '') {
                    params.consignee_phone = phoneField.value.trim();
                }
            }
            if (Object.keys(params).length === 0) {
                results.classList.add('hidden');
                results.innerHTML = '';
                return;
            }
            try {
                const res = await axios.get(lookupUrl, { params: params });
                renderResults(res.data.results || []);
            } catch (err) {
                results.classList.add('hidden');
            }
        }, DEBOUNCE_MS);

        input.addEventListener('input', function () {
            // If the operator edits the text after picking an order,
            // the previous selection is no longer trustworthy. Clear it
            // and let the typeahead re-suggest.
            if (hidden.value !== '') clearSelection();
            doSearch();
        });

        results.addEventListener('mousedown', function (e) {
            const li = e.target.closest('li[data-index]');
            if (!li) return;
            e.preventDefault();
            const idx = parseInt(li.dataset.index, 10);
            if (currentItems[idx]) pickItem(currentItems[idx]);
        });

        input.addEventListener('keydown', function (e) {
            if (results.classList.contains('hidden')) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(currentItems.length - 1, activeIndex + 1);
                updateActive();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(0, activeIndex - 1);
                updateActive();
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && currentItems[activeIndex]) {
                    e.preventDefault();
                    pickItem(currentItems[activeIndex]);
                }
            } else if (e.key === 'Escape') {
                results.classList.add('hidden');
            }
        });

        function updateActive() {
            const items = results.querySelectorAll('li[data-index]');
            items.forEach(function (li, i) {
                if (i === activeIndex) {
                    li.classList.add('bg-canvas');
                } else {
                    li.classList.remove('bg-canvas');
                }
            });
        }

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                results.classList.add('hidden');
            }
        });
    }

    document.querySelectorAll('[data-order-picker]').forEach(attach);
})();

// ---------------------------------------------------------------------------
// "Switch order" confirm on the shipment link form. The link form posts
// confirm=1 when the operator explicitly approves switching an already-
// linked shipment to a new order. The checkbox is only shown when the
// shipment is already linked to a different order.
// ---------------------------------------------------------------------------
(function () {
    document.querySelectorAll('[data-shipment-link-form]').forEach(function (form) {
        const confirmWrap = form.querySelector('[data-switch-confirm]');
        const orderInput = form.querySelector('[name="order_id"]');
        if (!confirmWrap || !orderInput) return;

        // Server pre-fills the form with the existing order's id when a
        // switch is attempted. We show the confirm checkbox if the picked
        // id is different from the existing one (rendered in a data attr).
        const currentId = String(confirmWrap.dataset.currentOrderId || '');
        const updateVisibility = function () {
            if (orderInput.value !== '' && orderInput.value !== currentId) {
                confirmWrap.classList.remove('hidden');
            } else {
                confirmWrap.classList.add('hidden');
            }
        };
        orderInput.addEventListener('change', updateVisibility);
        updateVisibility();
    });
})();

// ---------------------------------------------------------------------------
// Shipments table — row-level pickers.
//
// Each row on /shipments has up to two toggleable pickers: a "link"
// picker (Link / Switch button) and a "status" picker (Status button).
// Each picker is its own hidden <tr> with a unique id derived from
// `kind` + shipment id, so they don't collide.
//
// Clicking the same button again, or the row's Cancel button, hides
// the picker again. Clicking a different row's button collapses any
// other open pickers so only one is open at a time. Esc also collapses
// the open picker.
// ---------------------------------------------------------------------------
(function () {
    const table = document.querySelector('[data-shipments-table]');
    if (!table) return;

    function rowId(kind, shipmentId) {
        return 'shipment-picker-' + kind + '-' + shipmentId;
    }

    function closeAllExcept(kind, id) {
        table.querySelectorAll('[data-picker-row]:not(.hidden)').forEach(function (row) {
            const matchesKind = !kind || String(row.dataset.pickerKind) === String(kind);
            const matchesId = !id || String(row.dataset.pickerRow) === String(id);
            if (!(matchesKind && matchesId)) {
                row.classList.add('hidden');
            }
        });
    }

    function toggle(kind, id) {
        const row = document.getElementById(rowId(kind, id));
        if (!row) return;
        const willOpen = row.classList.contains('hidden');
        if (willOpen) {
            // Opening this picker closes any other picker (including a
            // link picker if a status picker is opening, and vice versa).
            closeAllExcept(null, null);
            row.classList.remove('hidden');
            // Focus the appropriate input based on which kind of picker it is.
            const orderInput = row.querySelector('[data-order-picker-input]');
            if (orderInput) {
                orderInput.focus();
            } else {
                const statusSelect = row.querySelector('[data-status-picker-select]');
                if (statusSelect) statusSelect.focus();
            }
        } else {
            row.classList.add('hidden');
        }
    }

    table.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-toggle-picker]');
        if (trigger) {
            event.preventDefault();
            const kind = trigger.dataset.pickerKind || 'link';
            toggle(kind, trigger.dataset.togglePicker);
            return;
        }
        const cancel = event.target.closest('[data-cancel-picker]');
        if (cancel) {
            event.preventDefault();
            const kind = cancel.dataset.cancelKind || 'link';
            toggle(kind, cancel.dataset.cancelPicker);
            return;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        const openRow = table.querySelector('[data-picker-row]:not(.hidden)');
        if (openRow) openRow.classList.add('hidden');
    });
})();

// ---------------------------------------------------------------------------
// New-shipment form — auto-generate the tracking number.
//
// The tracking number field is optional: leave it blank to have the
// server auto-generate one on submit. For better UX we still populate
// it with a fresh value as soon as the form renders, so the operator
// can see what number will be used (and override if they need to).
// A "Generate" button next to the input refreshes the value at any time.
// ---------------------------------------------------------------------------
(function () {
    const input = document.querySelector('[data-tracking-number-input]');
    if (!input) return;
    const button = document.querySelector('[data-generate-tracking-number]');
    const url = button ? button.dataset.generateUrl : null;
    if (!url) return;

    function generate() {
        // Optimistic client-side prefix so the field updates instantly.
        // The server-side value always wins on submit — this just makes
        // the UX feel snappy when the user clicks "Generate".
        const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let random = '';
        for (let i = 0; i < 8; i++) {
            random += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
        }
        input.value = 'MNL-' + random;
    }

    // If the operator hasn't touched the field yet, fill it on load.
    if (input.value === '' || input.value == null) {
        generate();
    }

    // Track whether the user has typed their own value; if they have,
    // we won't clobber it on Generate clicks.
    let userEdited = input.dataset.userEdited === '1';
    input.addEventListener('input', function () { userEdited = true; });

    button.addEventListener('click', function (event) {
        event.preventDefault();
        generate();
        userEdited = false;
    });
})();

