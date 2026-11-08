import './bootstrap';
import './radio';
import { start as startInstall } from './install';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// Installable app: service worker, install banner and the /app page.
startInstall();

// The arena's motion is a separate chunk, fetched only on the home page.
const arena = document.getElementById('arena');
if (arena) import('./arena.js').then((m) => m.start(arena));

// The homepage reels strip, likewise only where it appears. The pulse refresh swaps it when a new
// reel arrives (or one turns a week old), so stopReels lets the old strip let go first.
let stopReels = () => {};
const startReels = (hero) => import('./home-reels.js').then((m) => { stopReels = m.start(hero); });
if (document.getElementById('reels-hero')) startReels(document.getElementById('reels-hero'));

// Full-time banter cards for WhatsApp status, only on finished match pages.
const banter = document.querySelector('[data-banter]');
if (banter) import('./banter.js').then((m) => m.start(banter));

// "Alert me" and phone alerts, only on pages that offer them.
if (document.querySelector('[data-alert-toggle], [data-push-panel]')) import('./alerts.js').then((m) => m.start());

const pulse = document.getElementById('matchday-pulse');
if (pulse) {
    const refreshPulse = async () => {
        try {
            const reels = document.getElementById('reels-hero');
            const url = new URL(pulse.dataset.pulseUrl, window.location.href);
            if (reels) url.searchParams.set('reels', reels.dataset.reelsKey || '');
            const response = await fetch(url, {headers: {'Accept': 'application/json'}});
            if (!response.ok) return;
            const data = await response.json();
            pulse.innerHTML = data.html;
            if (reels && data.reels_html) {
                // A new reel (or one a week old dropped): swap the strip, unless the viewer has the sound on.
                const listening = reels.querySelector('[data-reels-sound][aria-pressed="true"]');
                if (!listening) {
                    stopReels();
                    reels.outerHTML = data.reels_html;
                    const fresh = document.getElementById('reels-hero');
                    if (fresh) startReels(fresh);
                }
            }
            // Live scores lead the homepage while a match is on (partials/home/live-now).
            const liveNow = document.getElementById('home-live');
            if (liveNow && data.live !== undefined) liveNow.outerHTML = data.live;
            if (data.arena) document.getElementById('arena')?.dispatchEvent(new CustomEvent('arena:update', {detail: data.arena}));
            window.setTimeout(refreshPulse, data.has_live ? 30000 : 120000);
        } catch (_) { window.setTimeout(refreshPulse, 120000); }
    };
    window.setTimeout(refreshPulse, 30000);
}

// Live score, clock and incidents: every 20s in play, every minute around kick-off.
const live = document.getElementById('match-live');
if (live && (live.dataset.active === '1' || live.dataset.soon === '1')) {
    let lastHtml = null;
    let lastStory = null;
    let lastTimeline = null;
    const refreshLive = async () => {
        let delay = 60000;
        try {
            const response = await fetch(live.dataset.url, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error('Live data unavailable');
            const data = await response.json();
            document.getElementById('match-home-score').textContent = data.score.home ?? '–';
            document.getElementById('match-away-score').textContent = data.score.away ?? '–';
            const status = document.getElementById('match-status');
            if (status) status.textContent = data.status;
            if (data.html !== lastHtml) live.innerHTML = data.html;
            const story = document.getElementById('match-story');
            if (story && data.story !== undefined && data.story !== lastStory) { story.innerHTML = data.story; lastStory = data.story; }
            const timeline = document.getElementById('match-timeline');
            // Keep a clip that is playing: only swap the timeline when nothing in it is being heard.
            const hearing = timeline && [...timeline.querySelectorAll('audio')].some((a) => !a.paused);
            if (timeline && data.timeline !== undefined && data.timeline !== lastTimeline && !hearing) { timeline.innerHTML = data.timeline; lastTimeline = data.timeline; }
            lastHtml = data.html;
            if (data.finished) return;
            delay = data.active ? 20000 : 60000;
        } catch (_) { /* retry on the slower cadence */ }
        window.setTimeout(refreshLive, delay);
    };
    window.setTimeout(refreshLive, live.dataset.active === '1' ? 20000 : 60000);
}

const chronicle = document.getElementById('match-chronicle');
if (chronicle) {
    const refreshChronicle = async () => {
        try {
            const response = await fetch(chronicle.dataset.url, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error('Chronicle unavailable');
            const data = await response.json();
            if (data.signature !== chronicle.dataset.signature) {
                chronicle.innerHTML = data.html;
                chronicle.dataset.signature = data.signature;
                document.getElementById('match-home-score').textContent = data.score.home ?? '–';
                document.getElementById('match-away-score').textContent = data.score.away ?? '–';
                chronicle.classList.remove('md-chronicle-flash');
                void chronicle.offsetWidth;
                chronicle.classList.add('md-chronicle-flash');
            }
            window.setTimeout(refreshChronicle, data.active ? 20000 : 120000);
        } catch (_) { window.setTimeout(refreshChronicle, 60000); }
    };
    window.setTimeout(refreshChronicle, chronicle.dataset.active === '1' ? 20000 : 120000);
}

// Share bars: "Copy link" copies the UTM-tagged link (resources/views/components/share-bar.blade.php).
document.addEventListener('click', (event) => {
    const button = event.target.closest('.md-copy-link');
    if (!button) return;
    const label = button.querySelector('span');
    const done = () => {
        if (!label) return;
        label.textContent = 'Link copied';
        window.setTimeout(() => { label.textContent = 'Copy link'; }, 2000);
    };
    const fallback = () => window.prompt('Copy this link', button.dataset.copy);
    if (navigator.clipboard) navigator.clipboard.writeText(button.dataset.copy).then(done, fallback);
    else fallback();
});
