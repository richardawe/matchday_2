document.querySelectorAll('[data-radio-player]').forEach((player) => {
    const audio = player.querySelector('audio');
    const play = player.querySelector('[data-radio-play]');
    const label = player.querySelector('[data-radio-label]');
    const icon = player.querySelector('[data-radio-icon]');
    const feedback = player.querySelector('[data-radio-feedback]');
    const seek = player.querySelector('[data-radio-seek]');
    const elapsed = player.querySelector('[data-radio-elapsed]');
    const stories = player.querySelector('[data-radio-stories]');
    const slides = [...player.querySelectorAll('[data-radio-story]')];
    const syncStory = () => {
        if (!slides.length) return;
        let active = 0;
        slides.forEach((slide, index) => { if (audio.currentTime >= Number(slide.dataset.start)) active = index; });
        slides.forEach((slide, index) => { slide.hidden = index !== active; });
    };
    ['timeupdate', 'seeked', 'loadedmetadata'].forEach(event => audio.addEventListener(event, syncStory));
    audio.addEventListener('play', () => { if (stories) { stories.hidden = false; syncStory(); } });
    if (play) {
        player.querySelector('[data-radio-controls]').hidden = false;
        audio.controls = false;
        audio.hidden = true;
        play.addEventListener('click', async () => {
            feedback.textContent = '';
            if (!audio.paused) { audio.pause(); return; }
            if (audio.ended) audio.currentTime = 0;
            label.textContent = 'Loading…';
            try { await audio.play(); }
            catch { label.textContent = 'Try again'; feedback.textContent = 'Audio could not load. Tap to try again.'; }
        });
        const sync = () => {
            const playing = !audio.paused && !audio.ended;
            label.textContent = playing ? 'Pause' : audio.currentTime > 0 && !audio.ended ? 'Resume' : 'Listen now';
            icon.textContent = playing ? 'Ⅱ' : '▶';
            play.setAttribute('aria-label', label.textContent);
            play.setAttribute('aria-pressed', String(playing));
        };
        ['play', 'pause', 'ended'].forEach((event) => audio.addEventListener(event, sync));
        audio.addEventListener('timeupdate', () => {
            seek.max = String(Number.isFinite(audio.duration) ? audio.duration : Number(seek.max));
            seek.value = String(audio.currentTime);
            elapsed.textContent = `${Math.floor(audio.currentTime / 60)}:${String(Math.floor(audio.currentTime % 60)).padStart(2, '0')}`;
        });
        audio.addEventListener('play', () => { player.querySelector('[data-radio-progress]').hidden = false; });
        seek.addEventListener('input', () => { if (Number.isFinite(audio.duration)) audio.currentTime = Number(seek.value); });
        audio.addEventListener('error', () => { sync(); feedback.textContent = 'Audio could not load. Tap to try again.'; });
    }
    player.querySelector('[data-radio-share]')?.addEventListener('click', async () => {
        const url = player.dataset.shareUrl;
        try {
            if (navigator.share) await navigator.share({ title: player.dataset.shareTitle, text: 'Listen to Matchday Africa Radio', url });
            else { await navigator.clipboard.writeText(url); feedback.textContent = 'Link copied'; }
        } catch (error) {
            if (error.name !== 'AbortError') {
                feedback.replaceChildren();
                const link = document.createElement('a');
                link.href = url; link.textContent = 'Open this bulletin to share its link';
                feedback.append(link);
            }
        }
    });
    audio.addEventListener('play', () => {
        document.querySelectorAll('audio, video').forEach((other) => { if (other !== audio) other.pause(); });
    });
});

document.addEventListener('volumechange', (event) => {
    if (event.target instanceof HTMLVideoElement && !event.target.muted) {
        document.querySelectorAll('[data-radio-player] audio').forEach((audio) => audio.pause());
    }
}, true);
