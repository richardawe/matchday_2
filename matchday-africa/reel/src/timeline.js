// The reel's running order, shared by the picture (Reel.jsx) and the soundtrack (audio.mjs)
// so drum hits land exactly on their scenes. 20 seconds at 30 fps:
//   0–2s the final score and the key moment (fully drawn on frame 1: viewers decide in ~2s)
//   · 2–16s one beat per big moment · 16–18s the numbers · 18–20s full time.
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

// The moment that decided the match, for the opening frame: the winner's go-ahead goal that
// was never pegged back; in a draw the last goal; with no goals the first red card.
export function keyMoment(beats) {
    const goals = beats.filter((b) => b.type === 'goal');
    const last = goals[goals.length - 1];
    if (last) {
        const diff = last.home_score - last.away_score;
        if (diff === 0) return last;
        const winner = diff > 0 ? 'home' : 'away';
        const lead = (b) => (winner === 'home' ? 1 : -1) * (b.home_score - b.away_score);
        let decisive = last;
        goals.forEach((goal, i) => {
            const before = i === 0 ? 0 : lead(goals[i - 1]);
            if (goal.side === winner && before <= 0 && lead(goal) > 0) decisive = goal;
        });
        return decisive;
    }
    return beats.find((b) => b.type === 'red_card') ?? null;
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

// ---------------------------------------------------------------------------------------------
// Style v2 (REEL_STYLE=v2): the bright "cartoon" reel. Each scene says how each army feels:
//   warrior   the statue as it stands (kick-off, a spell of pressure, level at the break)
//   victory   a goal, leading at half-time, winning at full time
//   dejected  conceding, hitting the woodwork, a big chance gone, a red card, a goal ruled out
//   defeated  losing at full time
// Length follows the story: 2.5 s face-off, a beat per moment (goals longer), 2.5 s numbers,
// 3 s verdict; between 20 and 45 seconds.
export const V2 = { title: 75, stats: 75, final: 90, min: 20 * FPS, max: 45 * FPS };
const BEAT_FRAMES = { goal: 96, red_card: 84, var: 78, woodwork: 72, big_chance: 72, injury: 66, pressure: 66, kickoff: 60, half_time: 66 };
// Lowest first: what goes when a busy match has more moments than fit in 45 seconds.
const DROP_ORDER = ['pressure', 'injury', 'kickoff', 'big_chance', 'half_time', 'woodwork', 'var', 'red_card'];

export function pickBeatsV2(beats) {
    let chosen = beats.filter((b) => b.type in BEAT_FRAMES);
    const span = () => chosen.reduce((sum, b) => sum + BEAT_FRAMES[b.type], 0);
    const room = V2.max - V2.title - V2.stats - V2.final;
    for (const kind of DROP_ORDER) {
        // Drop the least important kind, earliest first, until the moments fit.
        while (span() > room && chosen.some((b) => b.type === kind)) {
            const i = chosen.findIndex((b) => b.type === kind);
            chosen = [...chosen.slice(0, i), ...chosen.slice(i + 1)];
        }
    }
    // Still too long (a goal-fest): keep the last goals, as the 20-second reel does.
    while (span() > room) chosen = chosen.slice(1);
    return chosen;
}

const lead = (beat) => (beat ? beat.home_score - beat.away_score : 0);
const other = (side) => (side === 'home' ? 'away' : 'home');

// How each side feels in a moment: { home, away } of warrior | victory | dejected | defeated.
export function moods(beat) {
    const both = (home, away) => ({ home, away });
    const forSide = (side, mine, theirs) => (side === 'away' ? both(theirs, mine) : both(mine, theirs));
    switch (beat.type) {
        case 'goal':
            return forSide(beat.side ?? 'home', 'victory', 'dejected');
        case 'red_card': case 'var': case 'woodwork': case 'big_chance': case 'injury':
            return beat.side ? forSide(beat.side, 'dejected', 'warrior') : both('warrior', 'warrior');
        case 'half_time': {
            const diff = lead(beat);
            return diff === 0 ? both('warrior', 'warrior') : diff > 0 ? both('victory', 'dejected') : both('dejected', 'victory');
        }
        default:
            return both('warrior', 'warrior');
    }
}

export function finalMoods(home, away) {
    if (home.score === away.score) return { home: 'warrior', away: 'warrior' };
    return home.score > away.score ? { home: 'victory', away: 'defeated' } : { home: 'defeated', away: 'victory' };
}

// The side a scene is about (drawn large), or null for both.
export function focus(beat) {
    if (beat.type === 'half_time') return lead(beat) === 0 ? null : lead(beat) > 0 ? 'home' : 'away';
    return beat.side ?? null;
}

function buildTimelineV2(beats) {
    const picked = pickBeatsV2(beats);
    const scenes = [{ kind: 'title', from: 0, duration: V2.title }];
    let at = V2.title;
    for (const beat of picked) {
        scenes.push({ kind: 'beat', beat, from: at, duration: BEAT_FRAMES[beat.type] });
        at += BEAT_FRAMES[beat.type];
    }
    // A quiet match still lasts 20 seconds: the numbers get the spare time.
    const stats = Math.max(V2.stats, V2.min - at - V2.final);
    scenes.push({ kind: 'stats', from: at, duration: stats });
    scenes.push({ kind: 'final', from: at + stats, duration: V2.final });
    return scenes;
}

// The running order for a reel's props, in either style.
export function timelineFor(props) {
    return props?.style === 'v2' ? buildTimelineV2(props.beats ?? []) : buildTimeline(props?.beats ?? []);
}

export function totalFrames(props) {
    const scenes = timelineFor(props);
    const last = scenes[scenes.length - 1];
    return last.from + last.duration;
}

export { other };
