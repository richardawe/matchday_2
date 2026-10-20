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

// Choosing sound on a reel gives that reel the audio focus as well.
document.addEventListener('volumechange', (event) => {
    if (event.target instanceof HTMLVideoElement && !event.target.muted) {
        document.querySelectorAll('[data-radio-player] audio').forEach((audio) => audio.pause());
    }
}, true);
