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
