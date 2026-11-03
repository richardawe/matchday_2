// The homepage reels hero: a swipeable strip of reels (or, before any reels, fixture cards).
// The reel on screen plays muted by itself while the hero is in view; swiping, the arrows, the
// dots' order or a thumbnail in the rail moves the strip. "Sound on" applies to every reel. If the
// browser blocks autoplay (Low Power Mode, data saver), a "Tap to play" button appears instead.
export function start(hero) {
    const track = hero.querySelector('[data-reels-track]');
    if (!track) return;
    const slides = [...track.querySelectorAll('[data-reel-slide]')];
    const dots = [...hero.querySelectorAll('.md-reels-dots i')];
    const sound = hero.querySelector('[data-reels-sound]');
    const tap = hero.querySelector('[data-reels-tap]');
    const hint = hero.querySelector('[data-reels-hint]');
    const title = hero.querySelector('[data-reels-title]');
    const link = hero.querySelector('[data-reels-link]');
    const share = hero.querySelector('[data-reels-share]');
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let active = 0;
    let visible = true;
    let muted = true;
    let paused = false; // the viewer paused this reel by tapping it

    const video = (i) => slides[i]?.querySelector('video');
    const play = () => {
        const v = video(active);
        if (!v || !visible || paused) return;
        v.muted = muted;
        v.play().then(() => tap && (tap.hidden = true)).catch(() => tap && (tap.hidden = false));
    };
    const go = (i) => {
        const target = slides[Math.max(0, Math.min(slides.length - 1, i))];
        target && track.scrollTo({ left: target.offsetLeft, behavior: still ? 'auto' : 'smooth' });
    };

    const select = (i) => {
        if (i === active && video(i) && !video(i).paused) return;
        slides.forEach((_, j) => j !== i && video(j)?.pause());
        active = i;
        paused = false;
        dots.forEach((d, j) => d.classList.toggle('is-on', j === i));
        const s = slides[i].dataset;
        if (s.reelTitle) {
            if (title) title.textContent = s.reelTitle;
            if (link) link.href = s.reelLink;
            if (share) share.href = `https://wa.me/?text=${encodeURIComponent(`Watch ${s.reelTitle} ${s.reelShare || s.reelLink}`)}`;
        }
        play();
    };

    // Which slide is on screen.
    if ('IntersectionObserver' in window) {
        const seen = new IntersectionObserver((entries) => entries.forEach((e) => {
            if (e.isIntersecting && e.intersectionRatio > 0.6) select(slides.indexOf(e.target));
        }), { root: track, threshold: [0.6] });
        slides.forEach((s) => seen.observe(s));
        // Pause when the hero scrolls away, play again when it comes back.
        new IntersectionObserver(([e]) => {
            visible = e.isIntersecting;
            visible ? play() : video(active)?.pause();
        }, { threshold: 0.4 }).observe(track);
    } else {
        select(0);
    }

    // The swipe effect: neighbours tilt and shrink as they slide in and out.
    if (!still && slides.length > 1) {
        let frame = 0;
        const tilt = () => {
            frame = 0;
            const width = track.clientWidth || 1;
            slides.forEach((s) => {
                const d = (s.offsetLeft - track.scrollLeft) / width;
                const k = Math.min(1, Math.abs(d));
                s.style.transform = k < 0.01 ? '' : `scale(${1 - k * 0.12}) rotate(${d * 5}deg)`;
                s.style.opacity = String(1 - k * 0.35);
            });
        };
        track.addEventListener('scroll', () => { frame ||= requestAnimationFrame(tilt); hint && (hint.hidden = true); }, { passive: true });
        // Show once that the strip moves: a short peek at the next slide.
        if (hint) setTimeout(() => {
            if (track.scrollLeft > 0) return;
            track.scrollTo({ left: track.clientWidth * 0.18, behavior: 'smooth' });
            setTimeout(() => track.scrollLeft < track.clientWidth * 0.5 && track.scrollTo({ left: 0, behavior: 'smooth' }), 650);
        }, 1600);
    }

    hero.querySelector('[data-reels-prev]')?.addEventListener('click', () => go(active - 1));
    hero.querySelector('[data-reels-next]')?.addEventListener('click', () => go(active + 1));
    track.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') { e.preventDefault(); go(active + 1); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); go(active - 1); }
    });
    hero.querySelectorAll('[data-reel-goto]').forEach((thumb) => thumb.addEventListener('click', () => {
        go(Number(thumb.dataset.reelGoto));
        track.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'nearest' });
    }));

    // Tap a reel to pause or resume it.
    slides.forEach((s, i) => video(i)?.addEventListener('click', () => {
        const v = video(i);
        if (v.paused) { paused = false; play(); } else { paused = true; v.pause(); }
    }));
    tap?.addEventListener('click', () => { paused = false; play(); });

    sound?.addEventListener('click', () => {
        muted = !muted;
        sound.setAttribute('aria-pressed', String(!muted));
        sound.textContent = muted ? 'Sound on' : 'Sound off';
        paused = false;
        play();
    });
}
