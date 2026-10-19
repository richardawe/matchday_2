// The home-page arena. The page is complete without this file (a still image); this adds
// the entrance, pointer parallax, drifting dust and live score updates with GSAP, then
// upgrades capable devices to the three.js scene (arena3d.js, loaded only when used).
import { gsap } from 'gsap';

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function start(arena) {
    countdown(arena);
    listen(arena);
    if (reduced()) return;

    const home = arena.querySelector('.md-arena-home');
    const away = arena.querySelector('.md-arena-away');
    const hud = arena.querySelector('.md-arena-hud');
    const tl = gsap.timeline({ defaults: { ease: 'expo.out' } });
    if (home) tl.from(home, { xPercent: -18, opacity: 0, duration: 1.8 }, 0);
    if (away) tl.from(away, { xPercent: 18, opacity: 0, duration: 1.8 }, 0);
    tl.from(arena.querySelectorAll('.md-arena-glow'), { opacity: 0, scale: 0.7, duration: 2.2 }, 0.2)
        .from(hud.children, { y: 24, opacity: 0, duration: 1, stagger: 0.08 }, 0.6)
        .from(arena.querySelectorAll('.md-arena-drum'), { rotateX: -90, transformOrigin: '50% 50% -20px', duration: 0.9, stagger: 0.12, ease: 'back.out(1.6)' }, 0.9);

    // Parallax: the armies lean away from the pointer.
    if (window.matchMedia('(pointer: fine)').matches) {
        const moves = [home, away].filter(Boolean).map((el, i) => gsap.quickTo(el, 'x', { duration: 1.2, ease: 'power3.out' }));
        arena.addEventListener('pointermove', (e) => {
            const dx = (e.clientX / window.innerWidth - 0.5) * 2;
            moves.forEach((move, i) => move(dx * (i === 0 ? -18 : 18)));
        });
    }
    dust(arena);
    if (capable()) {
        const load = () => import('./arena3d.js').then((m) => m.mount(arena)).catch(() => {});
        'requestIdleCallback' in window ? window.requestIdleCallback(load, { timeout: 2500 }) : setTimeout(load, 1200);
    }
}

// Three.js only on larger screens that will run it smoothly, and never when saving data.
function capable() {
    // Phones get the lighter version: it reads better on a small screen and saves 120 KB.
    if (window.innerWidth < 900) return false;
    const connection = navigator.connection || {};
    if (connection.saveData || /2g/.test(connection.effectiveType || '')) return false;
    if ((navigator.deviceMemory ?? 4) < 4 || (navigator.hardwareConcurrency ?? 4) < 4) return false;
    try { return !!document.createElement('canvas').getContext('webgl2'); } catch (_) { return false; }
}

// Gold and team-coloured motes drifting up through the light; paused when offscreen.
function dust(arena) {
    const canvas = arena.querySelector('.md-arena-dust');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const colours = ['#edc781', arena.dataset.homeColour, arena.dataset.awayColour];
    let w = 0, h = 0, raf = null;
    const size = () => { w = canvas.width = canvas.offsetWidth; h = canvas.height = canvas.offsetHeight; };
    size();
    window.addEventListener('resize', size);
    const motes = Array.from({ length: 70 }, () => ({ x: Math.random(), y: Math.random(), r: Math.random() * 1.8 + 0.4, v: Math.random() * 0.0006 + 0.0002, c: colours[Math.floor(Math.random() * 3)], a: Math.random() * 0.5 + 0.2 }));
    const frame = () => {
        ctx.clearRect(0, 0, w, h);
        for (const m of motes) {
            m.y -= m.v; m.x += Math.sin(m.y * 12) * 0.0004;
            if (m.y < -0.02) { m.y = 1.02; m.x = Math.random(); }
            ctx.globalAlpha = m.a; ctx.fillStyle = m.c;
            ctx.beginPath(); ctx.arc(m.x * w, m.y * h, m.r, 0, Math.PI * 2); ctx.fill();
        }
        raf = requestAnimationFrame(frame);
    };
    new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting && !raf) raf = requestAnimationFrame(frame);
        else if (!entry.isIntersecting && raf) { cancelAnimationFrame(raf); raf = null; }
    }).observe(arena);
}

function countdown(arena) {
    const el = arena.querySelector('[data-arena-countdown]');
    if (!el) return;
    const kickoff = new Date(arena.dataset.kickoff).getTime();
    const tick = () => {
        const left = Math.max(0, kickoff - Date.now());
        if (left > 48 * 3600e3) return;
        const h = Math.floor(left / 3600e3), m = Math.floor((left % 3600e3) / 60e3), s = Math.floor((left % 60e3) / 1e3);
        el.textContent = left ? `${h}h ${String(m).padStart(2, '0')}m ${String(s).padStart(2, '0')}s` : 'KICK-OFF';
        if (left) setTimeout(tick, 1000);
    };
    tick();
}

// The home pulse refresh (app.js) sends the arena's latest score; a new goal turns the drum.
function listen(arena) {
    arena.addEventListener('arena:update', ({ detail }) => {
        if (!detail || String(detail.match_id) !== arena.dataset.match) return;
        const label = arena.querySelector('[data-arena-label]');
        if (label && detail.label) label.textContent = detail.label.toUpperCase();
        for (const side of ['home', 'away']) {
            const drum = arena.querySelector(`[data-arena-${side}]`);
            if (!drum || detail[side] === null || String(detail[side]) === drum.textContent.trim()) continue;
            const scored = Number(detail[side]) > Number(drum.textContent);
            if (reduced()) { drum.textContent = detail[side]; continue; }
            gsap.timeline()
                .to(drum, { rotateX: 90, duration: 0.25, ease: 'power2.in', onComplete: () => { drum.textContent = detail[side]; } })
                .to(drum, { rotateX: 0, duration: 0.45, ease: 'back.out(2)' });
            if (scored) {
                arena.classList.remove('md-arena-flash'); void arena.offsetWidth; arena.classList.add('md-arena-flash');
                arena.dispatchEvent(new CustomEvent('arena:goal', { detail: { side } }));
            }
        }
    });
}
