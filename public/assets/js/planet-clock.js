(() => {
    'use strict';
    const page = document.querySelector('[data-server-now]');
    if (!page) return;
    const sampledAt = Number(page.dataset.serverNow);
    if (!Number.isFinite(sampledAt)) return;
    const monotonicAtLoad = performance.now() / 1000;
    const labels = [...document.querySelectorAll('[data-due]')];
    if (!labels.length) return;
    // Display only: authoritative settlement and completion belong to the server.
    const refresh = () => {
        const now = sampledAt + (performance.now() / 1000 - monotonicAtLoad);
        for (const label of labels) {
            const due = Number(label.dataset.due);
            if (!Number.isFinite(due)) continue;
            const remaining = Math.max(0, Math.floor(due - now));
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;
            label.textContent = hours > 0 ? `${hours}h ${String(minutes).padStart(2, '0')}m remaining` : `${minutes}m ${String(seconds).padStart(2, '0')}s remaining`;
        }
    };
    refresh();
    window.setInterval(refresh, 1000);
})();
