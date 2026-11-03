// "Alert me" on matches and web push on this device. Alerts need an account: the button is only
// interactive for signed-in users (guests see a note with sign-up links, rendered server-side).
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const toast = (text) => {
    const el = document.createElement('div');
    el.className = 'md-alert-toast';
    el.setAttribute('role', 'status');
    el.textContent = text;
    document.body.appendChild(el);
    window.setTimeout(() => el.remove(), 4500);
};

const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

const keyBytes = (base64url) => {
    const padded = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
};

// Asks this browser for permission (only ever after a tap) and registers it with the site.
export async function enablePush(vapid) {
    if (!vapid) return 'off';
    if (!pushSupported()) {
        // iPhones only allow web push once the site is on the Home Screen.
        return /iPhone|iPad/.test(navigator.userAgent) ? 'ios' : 'unsupported';
    }
    if (Notification.permission === 'denied') return 'denied';
    const registration = await navigator.serviceWorker.register('/sw.js');
    const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
    if (permission !== 'granted') return 'denied';
    const subscription = (await registration.pushManager.getSubscription())
        ?? await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(vapid) });
    const response = await fetch('/push/subscriptions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(subscription.toJSON()),
    });
    return response.ok ? 'on' : 'error';
}

const PUSH_MESSAGES = {
    on: 'Alerts are on for this phone.',
    denied: 'Alerts are blocked in this browser’s settings. You’ll still find them in your inbox.',
    ios: 'On iPhone: tap Share, then “Add to Home Screen”, open Matchday Africa from there and turn alerts on.',
    unsupported: 'This browser can’t show alerts. You’ll find them in your inbox (the bell).',
    error: 'Couldn’t turn alerts on just now. Try again in a moment.',
};

async function toggle(button) {
    const on = button.dataset.on !== '1';
    button.disabled = true;
    try {
        const response = await fetch(button.dataset.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ on }),
        });
        if (response.status === 401 || response.redirected) { window.location.href = '/login'; return; }
        if (!response.ok) throw new Error(String(response.status));
        button.dataset.on = on ? '1' : '0';
        button.classList.toggle('is-on', on);
        const label = button.querySelector('[data-alert-label]');
        if (label) label.textContent = on ? '🔔 Alerts on' : '🔔 Alert me';
        else button.textContent = on ? 'Stop alerts' : 'Alert me';
        if (!on) { toast('Alerts off for this match.'); return; }
        const vapid = button.dataset.vapid;
        if (vapid && pushSupported() && Notification.permission !== 'granted') {
            const state = await enablePush(vapid);
            toast(state === 'on' ? 'You’ll get kick-off, goals and the result on this phone.' : PUSH_MESSAGES[state] ?? PUSH_MESSAGES.error);
        } else {
            if (vapid && pushSupported()) await enablePush(vapid); // keep this device registered
            toast('You’ll get kick-off, goals and the result' + (vapid ? ' on this phone.' : ' in your inbox.'));
        }
    } catch (_) {
        toast('Couldn’t change alerts just now. Try again in a moment.');
    } finally {
        button.disabled = false;
    }
}

export function start() {
    document.querySelectorAll('[data-alert-toggle]').forEach((button) => button.addEventListener('click', () => toggle(button)));
    const panel = document.querySelector('[data-push-panel]');
    const enable = panel?.querySelector('[data-push-enable]');
    if (enable) {
        const status = panel.querySelector('[data-push-status]');
        if (pushSupported() && Notification.permission === 'granted') {
            navigator.serviceWorker.getRegistration('/').then((r) => r?.pushManager.getSubscription()).then((s) => {
                if (s) { enable.textContent = 'Alerts are on here'; enable.disabled = true; }
            });
        }
        enable.addEventListener('click', async () => {
            enable.disabled = true;
            const state = await enablePush(panel.dataset.vapid).catch(() => 'error');
            if (status) status.textContent = PUSH_MESSAGES[state] ?? PUSH_MESSAGES.error;
            enable.textContent = state === 'on' ? 'Alerts are on here' : 'Turn on alerts here';
            enable.disabled = state === 'on';
        });
    }
}
