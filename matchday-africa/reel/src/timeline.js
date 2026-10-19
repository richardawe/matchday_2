// The reel's running order, shared by the picture (Reel.jsx) and the soundtrack (audio.mjs)
// so drum hits land exactly on their scenes. 20 seconds at 30 fps:
//   0–2s title · 2–16s one beat per big moment · 16–18s the numbers · 18–20s full time.
export const FPS = 30;
export const TOTAL = 20 * FPS;
const TITLE = 2 * FPS;
const STATS = 2 * FPS;
const FINAL = 2 * FPS;
const BEATS_SPAN = TOTAL - TITLE - STATS - FINAL;

// Goals and red cards first; a goalless match falls back to kick-off and half-time.
// At most five beats, keeping the last goals (they decide the story).
export function pickBeats(beats) {
    const big = beats.filter((b) => b.type === 'goal' || b.type === 'red_card');
    const chosen = big.length ? big : beats.filter((b) => b.type === 'kickoff' || b.type === 'half_time');
    return chosen.length > 5 ? chosen.slice(-5) : chosen;
}

export function buildTimeline(beats) {
    const picked = pickBeats(beats);
    const scenes = [{ kind: 'title', from: 0, duration: TITLE }];
    const each = picked.length ? Math.floor(BEATS_SPAN / picked.length) : BEATS_SPAN;
    picked.forEach((beat, i) => {
        const last = i === picked.length - 1;
        scenes.push({ kind: 'beat', beat, from: TITLE + i * each, duration: last ? BEATS_SPAN - i * each : each });
    });
    if (!picked.length) scenes.push({ kind: 'stats', from: TITLE, duration: BEATS_SPAN });
    scenes.push({ kind: 'stats', from: TITLE + BEATS_SPAN, duration: STATS });
    scenes.push({ kind: 'final', from: TOTAL - FINAL, duration: FINAL });
    return scenes;
}
