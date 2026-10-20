document.querySelectorAll('[data-radio-player]').forEach((player) => {
    const audio = player.querySelector('audio');
    player.querySelector('[data-radio-speed]')?.addEventListener('change', (event) => {
        audio.playbackRate = Number(event.target.value);
    });
    audio.addEventListener('play', () => {
        document.querySelectorAll('audio, video').forEach((other) => {
            if (other !== audio) other.pause();
        });
    });
});
