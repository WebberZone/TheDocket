/**
 * The reader page: appearance preferences (kept in localStorage, so they
 * are per browser) and reading progress (sent to the server, so it
 * follows you between devices).
 */
export const STORAGE_KEY = 'docket.reader';
export const DEFAULTS = { theme: 'light', family: 'serif', size: 20 };
export const SIZES = { min: 14, max: 32, step: 2 };

export function loadPreferences() {
    try {
        const saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}');

        return { ...DEFAULTS, ...saved };
    } catch {
        return { ...DEFAULTS };
    }
}

/**
 * Apply preferences to <html>. Also called from an inline script in the
 * page head so the right theme is there before first paint.
 */
export function applyPreferences(prefs, root = document.documentElement) {
    root.classList.remove('theme-light', 'theme-sepia', 'theme-dark', 'font-family-serif', 'font-family-sans');
    root.classList.add(`theme-${prefs.theme}`, `font-family-${prefs.family}`);
    root.style.setProperty('--reader-size', `${prefs.size}px`);
}

/**
 * Registered as Alpine.data('reader'). The progress endpoint and the saved
 * position come in as data attributes on the root element (the CSP build
 * can't take a config object built in the markup):
 *
 *   <body x-data="reader" data-progress-url="…" data-progress="0.42">
 */
export default () => ({
    prefs: loadPreferences(),
    panel: false,
    progressUrl: '',
    savedProgress: 0,
    lastSent: null,
    timer: null,

    init() {
        this.progressUrl = this.$el.dataset.progressUrl || '';
        this.savedProgress = Number(this.$el.dataset.progress) || 0;

        applyPreferences(this.prefs);

        this.$watch('prefs', (prefs) => {
            applyPreferences(prefs);

            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
            } catch {
                // Private browsing or storage full: the choice lasts until reload.
            }
        });

        // Restore the reading position once images have had a chance to
        // lay out, unless the article was barely started or finished.
        if (this.savedProgress > 0.01 && this.savedProgress < 0.99) {
            const restore = () => window.scrollTo({ top: this.savedProgress * this.scrollable(), behavior: 'instant' });
            if (document.readyState === 'complete') {
                restore();
            } else {
                window.addEventListener('load', restore, { once: true });
            }
        }

        this.lastSent = this.savedProgress;

        window.addEventListener('scroll', () => this.schedule(), { passive: true });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                this.send(true);
            }
        });
        window.addEventListener('pagehide', () => this.send(true));
    },

    setSize(delta) {
        const size = this.prefs.size + delta * SIZES.step;
        this.prefs.size = Math.min(SIZES.max, Math.max(SIZES.min, size));
    },

    scrollable() {
        return Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    },

    progress() {
        return Math.min(1, Math.max(0, window.scrollY / this.scrollable()));
    },

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.send(false), 1500);
    },

    send(leaving) {
        if (!this.progressUrl) {
            return;
        }

        const progress = Math.round(this.progress() * 10000) / 10000;

        if (this.lastSent !== null && Math.abs(progress - this.lastSent) < 0.005) {
            return;
        }

        this.lastSent = progress;

        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const body = new FormData();
        body.append('_token', token);
        body.append('progress', String(progress));

        // sendBeacon survives the page being closed; fetch is used
        // otherwise so failures don't go unnoticed in the console.
        if (leaving && navigator.sendBeacon) {
            navigator.sendBeacon(this.progressUrl, body);

            return;
        }

        fetch(this.progressUrl, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).catch(() => {});
    },
});
