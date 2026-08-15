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
