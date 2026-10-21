/**
 * The page side of offline reading (the work is done in public/sw.js).
 *
 *  - Asks the service worker to sync the offline copies whenever a logged-in
 *    page opens online, or the connection comes back. Logged-in pages carry
 *    <meta name="docket-offline">; the login page doesn't.
 *  - Keeps reading progress made offline in localStorage and sends it once
 *    there is a connection again.
 *  - Clears the offline copies before a form marked data-clears-offline
 *    (log out) submits.
 */
export const ARTICLES_CACHE = 'docket-articles';
export const PENDING_KEY = 'docket.pendingProgress';

function loggedIn() {
    return document.querySelector('meta[name="docket-offline"]') !== null;
}

export function requestSync() {
    if (!loggedIn() || !navigator.onLine || !('serviceWorker' in navigator)) {
        return;
    }

    navigator.serviceWorker.ready
        .then((registration) => registration.active?.postMessage({ type: 'sync' }))
        .catch(() => {});
}

function readPending() {
    try {
        return JSON.parse(window.localStorage.getItem(PENDING_KEY) || '{}');
    } catch {
        return {};
    }
}

function writePending(pending) {
    try {
        if (Object.keys(pending).length === 0) {
            window.localStorage.removeItem(PENDING_KEY);
        } else {
            window.localStorage.setItem(PENDING_KEY, JSON.stringify(pending));
        }
    } catch {
        // Storage unavailable: offline progress is lost, nothing else.
    }
}

/**
 * Remember progress that couldn't be sent, keyed by its progress URL.
 */
export function queueProgress(url, progress) {
    writePending({ ...readPending(), [url]: progress });
}

/**
 * Progress made offline for this article and not yet sent, if any. It is
 * newer than the position in an offline copy of the page.
 */
export function pendingProgress(url) {
    const value = readPending()[url];

    return typeof value === 'number' ? value : null;
}

/**
 * Drop queued progress for one article once newer progress has been sent.
 */
export function forgetProgress(url) {
    const pending = readPending();

    if (url in pending) {
        delete pending[url];
        writePending(pending);
    }
}

export function postProgress(url, progress) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const body = new FormData();
    body.append('_token', token);
    body.append('progress', String(progress));

    return fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
}

/**
 * Send any progress queued while offline. Entries the server rejects
 * outright (the article was deleted) are dropped; a network failure keeps
 * them for next time.
 */
export function flushProgress() {
    if (!loggedIn() || !navigator.onLine) {
        return;
    }

    Object.entries(readPending()).forEach(([url, progress]) => {
        postProgress(url, progress)
            .then((response) => {
                if (response.ok || (response.status >= 400 && response.status < 500 && response.status !== 419)) {
                    const pending = readPending();

                    if (pending[url] === progress) {
                        delete pending[url];
                        writePending(pending);
                    }
                }
            })
            .catch(() => {});
    });
}

export default function offline(root) {
    root.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-clears-offline') || !('caches' in window)) {
            return;
        }

        event.preventDefault();

        navigator.serviceWorker?.controller?.postMessage({ type: 'clear' });

        Promise.all([window.caches.delete(ARTICLES_CACHE), window.caches.delete('docket-assets')])
            .catch(() => {})
            .finally(() => form.submit());
    });

    window.addEventListener('online', () => {
        requestSync();
        flushProgress();
    });

    requestSync();
    flushProgress();
}
