// The reel's running order, shared by the picture (Reel.jsx), the soundtrack (audio.mjs) and the
// voice-over (voice.mjs) so drum hits and spoken lines land exactly on their scenes.
// 20 seconds at 30 fps:
//   0–3s title · 3–15s one beat per big moment · 15–17s the numbers · 17–20s full time and end card.
export const FPS = 30;
export const TOTAL = 20 * FPS;
const TITLE = 3 * FPS;
const STATS = 2 * FPS;
const FINAL = 3 * FPS;
const BEATS_SPAN = TOTAL - TITLE - STATS - FINAL;

// When a scene's first bubble pops, after its headline has landed.
const BUBBLE_LEAD = { title: 14, beat: 14, final: 8 };
// A bubble stays up for its spoken line plus a beat, or long enough to read when silent.
const READ_FRAMES = (text) => Math.round(FPS * Math.min(2.6, 0.9 + text.length * 0.035));

// Goals and red cards first; a goalless match falls back to kick-off and half-time.
// At most five beats, keeping the last goals (they decide the story).
export function pickBeats(beats) {
    const big = beats.filter((b) => b.type === 'goal' || b.type === 'red_card');
    const chosen = big.length ? big : beats.filter((b) => b.type === 'kickoff' || b.type === 'half_time');
    return chosen.length > 5 ? chosen.slice(-5) : chosen;
}

// Beats share the middle of the reel; a beat that carries a speech bubble gets a double share,
// so its line can be read and spoken.
export function buildTimeline(beats, bubbles = []) {
    const picked = pickBeats(beats);
    const scenes = [{ kind: 'title', from: 0, duration: TITLE }];
    const talky = new Set(bubbles.filter((b) => b.scene === 'beat').map((b) => b.beat));
    const weights = picked.map((beat) => (talky.has(beat.id) ? 2 : 1));
    const total = weights.reduce((a, b) => a + b, 0);
    let from = TITLE;
    picked.forEach((beat, i) => {
        const last = i === picked.length - 1;
        const duration = last ? TITLE + BEATS_SPAN - from : Math.floor((BEATS_SPAN * weights[i]) / total);
        scenes.push({ kind: 'beat', beat, from, duration });
        from += duration;
    });
    if (!picked.length) scenes.push({ kind: 'stats', from: TITLE, duration: BEATS_SPAN });
    scenes.push({ kind: 'stats', from: TITLE + BEATS_SPAN, duration: STATS });
    scenes.push({ kind: 'final', from: TOTAL - FINAL, duration: FINAL });
    return scenes;
}

// Which scene a bubble belongs to: the title, the final, or the beat it was written for.
const sceneFor = (scenes, bubble) => scenes.find((s) => (bubble.scene === 'beat' ? s.kind === 'beat' && s.beat.id === bubble.beat : s.kind === bubble.scene));

// How long each bubble may speak, in seconds, before the voice-over is made: a scene's room
// after its lead, shared between its bubbles.
export function speakingRoom(beats, bubbles) {
    const scenes = buildTimeline(beats, bubbles);
    return bubbles.map((bubble) => {
        const scene = sceneFor(scenes, bubble);
        if (!scene) return 0;
        const shared = bubbles.filter((b) => sceneFor(scenes, b) === scene).length;
        return Math.max(0, (scene.duration - BUBBLE_LEAD[scene.kind] - 6) / shared / FPS - 0.15);
    });
}

// Absolute frames for every bubble that fits: one after another within its scene, each up for
// its spoken line (`seconds`, from voice.mjs) or its reading time. Bubbles that would start too
// late in their scene are dropped.
export function placeBubbles(beats, bubbles = []) {
    const scenes = buildTimeline(beats, bubbles);
    const next = new Map();
    const placed = [];
    for (const bubble of bubbles) {
        const scene = sceneFor(scenes, bubble);
        if (!scene) continue;
        const end = scene.from + scene.duration - 4;
        const from = next.get(scene) ?? scene.from + BUBBLE_LEAD[scene.kind];
        const wanted = bubble.seconds ? Math.round(bubble.seconds * FPS) + 8 : READ_FRAMES(bubble.text);
        const duration = Math.min(wanted, end - from);
        if (duration < 18) continue;
        // `duration` is its speaking slot; `until` keeps the bubble on screen to the end of the scene.
        placed.push({ ...bubble, from, duration, until: end });
        next.set(scene, from + duration + 3);
    }
    return placed;
}
