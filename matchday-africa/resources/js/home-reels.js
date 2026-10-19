// The homepage reels hero: the featured reel plays muted while it is on screen (and only then,
// so phones don't spend data on a video nobody sees); "Sound on" unmutes; tapping a reel in the
// rail swaps it into the player instead of leaving the page.
export function start(hero) {
    const video = hero.querySelector('#reels-video');
    if (!video) return;
    const sound = hero.querySelector('[data-reels-sound]');
    const title = hero.querySelector('[data-reels-title]');
    const link = hero.querySelector('[data-reels-link]');
    const share = hero.querySelector('[data-reels-share]');
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const play = () => video.play().catch(() => {});
    if (!still && 'IntersectionObserver' in window) {
        new IntersectionObserver(([entry]) => (entry.isIntersecting ? play() : video.pause()), { threshold: 0.5 }).observe(video);
    }

    sound?.addEventListener('click', () => {
        video.muted = !video.muted;
        sound.setAttribute('aria-pressed', String(!video.muted));
        sound.textContent = video.muted ? 'Sound on' : 'Sound off';
        play();
    });

    hero.querySelectorAll('[data-reel-video]').forEach((thumb) => thumb.addEventListener('click', () => {
        const { reelVideo, reelPoster, reelTitle, reelLink } = thumb.dataset;
        video.poster = reelPoster || '';
        video.src = reelVideo;
        video.setAttribute('aria-label', `${reelTitle}: the 20-second reel`);
        if (title) title.textContent = reelTitle;
        if (link) link.href = reelLink;
        if (share) share.href = `https://wa.me/?text=${encodeURIComponent(`Watch ${reelTitle} in 20 seconds ${reelLink}`)}`;
        play();
        video.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'nearest' });
    }));
}
