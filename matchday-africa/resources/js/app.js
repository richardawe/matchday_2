import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

const pulse = document.getElementById('matchday-pulse');
if (pulse) {
    const refreshPulse = async () => {
        try {
            const response = await fetch(pulse.dataset.pulseUrl, {headers: {'Accept': 'application/json'}});
            if (!response.ok) return;
            const data = await response.json();
            pulse.innerHTML = data.html;
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
