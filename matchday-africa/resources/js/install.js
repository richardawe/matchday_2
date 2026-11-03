// Matchday Africa as an app on iPhone and Android: registers the service worker on every page
// (public/sw.js), keeps Chrome's install prompt for our own Install buttons, and runs the
// install banner (partials/install-banner) and the /app page (app-install.blade.php).
//
// Banner: "Install" on Android/desktop Chrome, Share → Add to Home Screen steps on iPhone, and
// "Turn on match alerts" once the app is opened from the Home Screen by a signed-in fan.
// Its counts live in localStorage (md_install) and are never sent anywhere.

const KEY = 'md_install';
const DAY = 864e5;

let deferredPrompt = null;

export const standalone = () => window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;
export const isIos = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

const load = () => { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (_) { return {}; } };
const save = (state) => { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (_) { /* private mode */ } };

const track = (event, properties = {}) => {
    const url = document.querySelector('[data-install-track]')?.dataset.installTrack;
    if (!url) return;
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
        body: JSON.stringify({ event, path: location.pathname, properties: { ...properties, standalone: standalone() } }),
        keepalive: true,
    }).catch(() => {});
};

// What this device can do right now: 'installed', 'prompt' (Chrome's install dialog is ready),
// 'ios' (Safari's Share menu) or 'manual' (the browser menu, or nothing).
export const mode = () => (standalone() ? 'installed' : deferredPrompt ? 'prompt' : isIos() ? 'ios' : 'manual');

// Opens Chrome's install dialog. Returns true when the fan accepted.
export async function install(from) {
    if (!deferredPrompt) return false;
    const prompt = deferredPrompt;
    deferredPrompt = null;
    prompt.prompt();
    const choice = await prompt.userChoice.catch(() => ({ outcome: 'dismissed' }));
    track('app_install_choice', { outcome: choice.outcome, from });
    document.dispatchEvent(new CustomEvent('md:install-mode'));
    return choice.outcome === 'accepted';
}

function banner() {
    const box = document.getElementById('md-install');
    if (!box) return;
    const state = load();
    const title = box.querySelector('[data-install-title]');
    const text = box.querySelector('[data-install-text]');
    const go = box.querySelector('[data-install-go]');
    const vapid = box.dataset.vapid;
    let variant = null;

    const hide = (dismissed) => {
        box.hidden = true;
        if (dismissed) { state.dismissed = { ...(state.dismissed || {}), [variant]: Date.now() }; save(state); track('app_banner_dismissed', { variant }); }
    };
    const quiet = (v, days) => Date.now() - (state.dismissed?.[v] || 0) < days * DAY;

    const pick = () => {
        if (standalone()) {
            // In the app: offer phone alerts once, to signed-in fans who haven't answered yet.
            if (vapid && pushSupported() && Notification.permission === 'default' && !quiet('alerts', 7)) return 'alerts';
            return null;
        }
        if ((state.views || 0) < 2) return null; // never on a first visit
        if (deferredPrompt && !quiet('prompt', 14)) return 'prompt';
        if (isIos() && !quiet('ios', 14)) return 'ios';
        return null;
    };

    const show = () => {
        variant = pick();
        if (!variant) { box.hidden = true; return; }
        // Never on top of the signup nudge: try again on the next page.
        if (document.getElementById('md-nudge')?.hidden === false) return;
        box.dataset.variant = variant;
        if (variant === 'alerts') {
            title.textContent = 'Turn on match alerts';
            text.textContent = 'Kick-off, goals and full time on this phone for the matches you follow.';
            go.textContent = 'Turn on';
        } else if (variant === 'ios') {
            title.textContent = 'Get the Matchday app';
            text.textContent = 'Tap Share, then “Add to Home Screen”. Free, no app store.';
            go.textContent = 'Show me';
        } else {
            title.textContent = 'Get the Matchday app';
            text.textContent = 'Scores, reels and goal alerts from your home screen. Free, no app store.';
            go.textContent = 'Install';
        }
        if (box.hidden) { box.hidden = false; track('app_banner_shown', { variant }); }
    };

    state.views = (state.views || 0) + 1;
    save(state);

    box.addEventListener('click', async (event) => {
        if (event.target.closest('[data-install-dismiss]')) { hide(true); return; }
        if (!event.target.closest('[data-install-go]')) return;
        track('app_banner_clicked', { variant });
        if (variant === 'prompt') {
            if (await install('banner')) hide(false); else show();
        } else if (variant === 'ios') {
            window.location.href = box.dataset.installPage + '#iphone';
        } else if (variant === 'alerts') {
            go.disabled = true;
            const { enablePush } = await import('./alerts.js');
            const result = await enablePush(vapid).catch(() => 'error');
            track('app_alerts_choice', { result });
            text.textContent = result === 'on' ? 'Alerts are on. Tap “Alert me” on any match to follow it.' : 'Alerts are off for now. You can turn them on from your inbox (the bell).';
            go.hidden = true;
            state.dismissed = { ...(state.dismissed || {}), alerts: Date.now() };
            save(state);
            window.setTimeout(() => hide(false), 5000);
        }
    });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !box.hidden) hide(true); });
    document.addEventListener('md:install-mode', show);
    window.setTimeout(show, 6000);
}

// The /app page: show the button or steps that fit this device.
function page() {
    const root = document.querySelector('[data-install-page]');
    if (!root) return;
    const render = () => {
        const current = mode();
        root.dataset.mode = current;
        root.querySelectorAll('[data-install-when]').forEach((el) => { el.hidden = !el.dataset.installWhen.split(' ').includes(current); });
    };
    root.addEventListener('click', async (event) => {
        if (!event.target.closest('[data-install-go]')) return;
        await install('page');
        render();
    });
    document.addEventListener('md:install-mode', render);
    render();
}

// The installed app's back button (components/app-back): back within the site, or home.
function backButton() {
    document.addEventListener('click', (event) => {
        const back = event.target.closest('[data-app-back]');
        if (!back) return;
        let sameSite = false;
        try { sameSite = document.referrer && new URL(document.referrer).origin === location.origin; } catch (_) { /* no referrer */ }
        if (sameSite && history.length > 1) { event.preventDefault(); history.back(); }
        // Otherwise the link's own href (home) is followed.
    });
}

export function start() {
    // iOS reports the home-screen app through navigator.standalone, not the display-mode query.
    if (standalone()) document.documentElement.classList.add('is-standalone');
    backButton();
    if ('serviceWorker' in navigator) {
        const register = () => navigator.serviceWorker.register('/sw.js').catch(() => {});
        if (document.readyState === 'complete') register(); else window.addEventListener('load', register, { once: true });
    }
    window.addEventListener('beforeinstallprompt', (event) => {
        // Keep the dialog for our own Install buttons instead of Chrome's bar at the bottom.
        event.preventDefault();
        deferredPrompt = event;
        document.dispatchEvent(new CustomEvent('md:install-mode'));
    });
    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        const box = document.getElementById('md-install');
        if (box) box.hidden = true;
        track('app_installed');
        document.dispatchEvent(new CustomEvent('md:install-mode'));
    });
    // One "app_open" per visit from the Home Screen icon (start_url carries utm_source=homescreen).
    if (standalone()) {
        try { if (!sessionStorage.getItem('md_app_open')) { sessionStorage.setItem('md_app_open', '1'); track('app_open'); } } catch (_) { /* private mode */ }
    }
    banner();
    page();
}
