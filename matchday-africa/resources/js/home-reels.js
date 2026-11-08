// The homepage reels strip: one row of reels from the last seven days, newest on the left.
// The first reel fully on screen plays muted while the strip is in view; when it ends the strip
// moves to the next (back to the newest after the last). Tapping a reel opens its match page.
// "Sound on" applies to every reel. The arrows and the keyboard move one reel at a time.
// start() returns a stop function so the strip can be swapped when a new reel arrives.
export function start(hero) {
    const track = hero.querySelector('[data-reels-track]');
    if (!track) return () => {};
    const slides = [...track.querySelectorAll('[data-reel-slide]')];
    const sound = hero.querySelector('[data-reels-sound]');
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const observers = [];
    const shown = new Set();
    let active = -1;
    let visible = true;
    let muted = true;

    const video = (i) => slides[i]?.querySelector('video');
    const go = (i) => {
        const target = slides[(i + slides.length) % slides.length];
        target && track.scrollTo({ left: target.offsetLeft - track.offsetLeft, behavior: still ? 'auto' : 'smooth' });
    };
    const play = () => {
        const v = video(active);
        if (!v || !visible) return;
        v.muted = muted;
        v.play().catch(() => {});
    };
    const select = (i) => {
        if (i === active) return;
        slides.forEach((_, j) => j !== i && video(j)?.pause());
        active = i;
        slides.forEach((s, j) => s.classList.toggle('is-on', j === i));
        const v = video(i);
        if (v) v.currentTime = 0;
        play();
    };

    if ('IntersectionObserver' in window) {
        // The playing reel keeps playing while it's on screen; once it's swiped away, the leftmost
        // reel that is (almost) fully on screen takes over.
        const seen = new IntersectionObserver((entries) => {
            entries.forEach((e) => (e.intersectionRatio > 0.85 ? shown.add(slides.indexOf(e.target)) : shown.delete(slides.indexOf(e.target))));
            if (shown.size && !shown.has(active)) select(Math.min(...shown));
        }, { root: track, threshold: [0, 0.85, 1] });
        slides.forEach((s) => seen.observe(s));
        // Pause when the strip scrolls out of view, play again when it comes back.
        const inView = new IntersectionObserver(([e]) => {
            visible = e.isIntersecting;
            visible ? play() : video(active)?.pause();
        }, { threshold: 0.4 });
        inView.observe(track);
        observers.push(seen, inView);
    } else {
        select(0);
    }

    // A reel that finishes hands over to the next one (back to the newest after the last).
    slides.forEach((_, i) => video(i)?.addEventListener('ended', () => {
        if (i !== active) return;
        const next = (i + 1) % slides.length;
        select(next);
        go(next);
    }));

    hero.querySelector('[data-reels-prev]')?.addEventListener('click', () => go(Math.max(active, 0) - 1));
    hero.querySelector('[data-reels-next]')?.addEventListener('click', () => go(Math.max(active, 0) + 1));
    track.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') { e.preventDefault(); go(Math.max(active, 0) + 1); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); go(Math.max(active, 0) - 1); }
    });

    sound?.addEventListener('click', () => {
        muted = !muted;
        sound.setAttribute('aria-pressed', String(!muted));
        sound.textContent = muted ? 'Sound on' : 'Sound off';
        play();
    });

    return () => {
        observers.forEach((o) => o.disconnect());
        slides.forEach((_, i) => video(i)?.pause());
    };
}
