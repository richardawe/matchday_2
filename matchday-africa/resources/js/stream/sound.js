/**
 * Sound for the stream: talking-drum calls made here with Web Audio (our own sound, no music or
 * samples, so nothing copyrighted reaches the stream), plus the Griot's voice clips when the site
 * has them. Browsers only allow sound after a tap or click, so `needsTap()` tells the page when to
 * show its "Tap to turn on sound" button; OBS browser sources and YouTube capture tools allow it.
 */
export function createSound() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    const ctx = Ctx ? new Ctx() : null;

    function drum(at, from, to, length, level = 0.9) {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(from, at);
        osc.frequency.exponentialRampToValueAtTime(to, at + length * 0.8);
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(level, at + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        osc.connect(gain).connect(ctx.destination);
        osc.start(at);
        osc.stop(at + length + 0.05);
    }

    function slap(at, level = 0.35) {
        const length = 0.08;
        const buffer = ctx.createBuffer(1, Math.ceil(ctx.sampleRate * length), ctx.sampleRate);
        const data = buffer.getChannelData(0);
        for (let i = 0; i < data.length; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / data.length) ** 3;
        const src = ctx.createBufferSource();
        const gain = ctx.createGain();
        gain.gain.value = level;
        src.buffer = buffer;
        src.connect(gain).connect(ctx.destination);
        src.start(at);
    }

    const ready = () => ctx && ctx.state === 'running';

    return {
        needsTap: () => !!ctx && ctx.state !== 'running',
        unlock: () => (ctx ? ctx.resume() : Promise.resolve()),
        goal() {
            if (!ready()) return;
            const t = ctx.currentTime + 0.05;
            [[0, 140, 220], [0.16, 140, 220], [0.32, 160, 260], [0.56, 120, 300], [0.9, 110, 330]].forEach(([dt, a, b], i) => {
                drum(t + dt, a, b, i === 4 ? 0.9 : 0.28);
                slap(t + dt);
            });
        },
        red() {
            if (!ready()) return;
            const t = ctx.currentTime + 0.05;
            drum(t, 120, 60, 0.6);
            drum(t + 0.45, 110, 50, 0.8);
        },
        voice(url, delay = 1300) {
            if (!url || !ready()) return;
            setTimeout(() => new Audio(url).play().catch(() => {}), delay);
        },
    };
}
