import './bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('dashboard-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const openBtn = document.getElementById('sidebar-open');
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

    openBtn?.addEventListener('click', openSidebar);
    closeBtn?.addEventListener('click', closeSidebar);
    backdrop?.addEventListener('click', closeSidebar);
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

    async function poll() {
        const since = anchor.dataset.since || '';
        const url = pollUrl + (since ? '?since=' + encodeURIComponent(since) : '');

        try {
            const res = await axios.get(url);
            const data = res.data;

            if (!data.changed) return;

            const rowsRes = await axios.get(rowsUrl + '?since=' + encodeURIComponent(since));
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

