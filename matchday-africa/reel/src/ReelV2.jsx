import React from 'react';
import { AbsoluteFill, Audio, Img, Sequence, interpolate, spring, staticFile, useCurrentFrame, useVideoConfig } from 'remotion';
import { finalMoods, focus, keyMoment, moods, timelineFor } from './timeline.js';

// Style v2: the same crest-free statues, told like a comic strip. Bright club-coloured skies,
// sunbursts and halftone, thick ink outlines, speech-box captions and a sound-effect burst on
// every moment. Each army has a mood per scene (timeline.js): its own pose art when the side
// has an approved image for it (props side.poses), otherwise a drawn treatment of the statue.
const INK = '#0b0b0b';
const PAPER = '#fff8e7';
const SUN = '#ffd23f';

const fonts = `
@font-face{font-family:Anton;src:url(${staticFile('fonts/Anton-Regular.ttf')})}
@font-face{font-family:Plex;src:url(${staticFile('fonts/IBMPlexSans.ttf')})}
@font-face{font-family:PlexMono;src:url(${staticFile('fonts/IBMPlexMono-SemiBold.ttf')})}`;

const useUnit = () => useVideoConfig().width / 1080;

// A brighter, punchier version of a kit colour for skies (near-black kits get lifted).
function tint(hex, amount) {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const mix = (c) => Math.round(amount >= 0 ? c + (255 - c) * amount : c * (1 + amount));
    return `rgb(${mix(r)},${mix(g)},${mix(b)})`;
}
const luma = (hex) => {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
// The colour a side is drawn in: its first kit colour, or the second when the first is near-black/white.
// A side's scorers for the opening frame: "Saka 23′ · Havertz 70′" (own goals marked OG).
const scorers = (beats, side) => (beats ?? [])
    .filter((b) => b.type === 'goal' && b.side === side && b.player)
    .map((b) => `${b.player.trim().split(/\s+/).pop()}${b.clock ? ` ${b.clock}` : ''}`)
    .join(' · ');

const hue = (side) => {
    const [a, b] = side.colours;
    const l = luma(a);
    return (l < 45 || l > 235) && b ? b : a;
};

// Deterministic randomness so every render of a match looks the same.
const rand = (seed) => {
    const x = Math.sin(seed * 127.1 + 311.7) * 43758.5453;
    return x - Math.floor(x);
};

const BURSTS = {
    goal: 'GOAL!', red_card: 'OFF!', var: 'NO GOAL!', woodwork: 'CLANG!', big_chance: 'SO CLOSE!',
    pressure: 'ATTACK!', injury: 'INJURY', kickoff: 'KICK-OFF!', half_time: 'HALF-TIME',
};

// The sky behind a side: club colour, a sunburst and halftone dots. Gloomy moods drain it.
const Sky = ({ colour, mood = 'warrior', x = '50%', y = '62%', clip }) => {
    const gloomy = mood === 'dejected' || mood === 'defeated';
    const base = gloomy ? (mood === 'defeated' ? '#6b6258' : '#4f6f9a') : colour;
    // Still rays: a turning sunburst repaints every pixel each frame and triples the file size.
    const spin = -8;
    return (
        <AbsoluteFill style={{ clipPath: clip }}>
            <AbsoluteFill style={{ background: `radial-gradient(120% 80% at ${x} ${y}, ${tint(base, gloomy ? 0.35 : 0.55)} 0%, ${tint(base, gloomy ? 0.1 : 0.1)} 45%, ${tint(base, -0.4)} 100%)` }} />
            {!gloomy && (
                <AbsoluteFill style={{ background: `repeating-conic-gradient(from ${spin}deg at ${x} ${y}, rgba(255,255,255,${mood === 'victory' ? 0.28 : 0.14}) 0deg 7deg, transparent 7deg 15deg)` }} />
            )}
            <AbsoluteFill style={{ background: 'radial-gradient(rgba(0,0,0,.12) 26%, transparent 28%) 0 0 / 26px 26px', opacity: gloomy ? 0.4 : 0.6 }} />
        </AbsoluteFill>
    );
};

// Confetti in the side's colours, gold and white, for a victory.
const Confetti = ({ colours, count = 44 }) => {
    const frame = useCurrentFrame();
    const { width, height } = useVideoConfig();
    const palette = [...colours, SUN, '#ffffff'];
    return (
        <AbsoluteFill style={{ pointerEvents: 'none' }}>
            {Array.from({ length: count }, (_, i) => {
                const x = rand(i) * width;
                const fall = (rand(i + 99) * 0.6 + 0.5) * 14;
                const y = -60 + ((frame * fall + rand(i + 7) * height) % (height + 120));
                const spin = frame * (rand(i + 3) * 16 - 8);
                const w = 12 + rand(i + 5) * 16;
                return <div key={i} style={{ position: 'absolute', left: x + Math.sin((frame + i * 9) / 9) * 24, top: y, width: w, height: w * 0.45, background: palette[i % palette.length], border: '2px solid #0b0b0b', transform: `rotate(${spin}deg)` }} />;
            })}
        </AbsoluteFill>
    );
};

// Rain for a dejected side, inside its half of the picture.
const Rain = ({ clip }) => {
    const frame = useCurrentFrame();
    const { width, height } = useVideoConfig();
    return (
        <AbsoluteFill style={{ clipPath: clip, pointerEvents: 'none' }}>
            {Array.from({ length: 36 }, (_, i) => {
                const x = rand(i + 40) * width;
                const y = ((frame * (26 + rand(i) * 12) + rand(i + 3) * height) % (height + 200)) - 100;
                return <div key={i} style={{ position: 'absolute', left: x, top: y, width: 4, height: 70, background: 'rgba(200,220,255,.55)', transform: 'rotate(12deg)', borderRadius: 2 }} />;
            })}
        </AbsoluteFill>
    );
};

// Cracks in the ground under a defeated army.
const Cracks = ({ left, bottom }) => {
    const { height } = useVideoConfig();
    const frame = useCurrentFrame();
    const grow = interpolate(frame, [4, 20], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
    return (
        <svg viewBox="0 0 600 160" style={{ position: 'absolute', left, bottom: height * bottom - 70, width: 600, height: 160, transform: 'translateX(-50%)', overflow: 'visible' }}>
            <path d="M300 10 L270 50 L300 70 L240 110 L260 150 M300 70 L360 95 L340 140 M270 50 L180 60 L120 95 M360 95 L470 80 L540 120" stroke={INK} strokeWidth="9" fill="none" strokeLinecap="round" strokeDasharray="900" strokeDashoffset={900 * (1 - grow)} />
        </svg>
    );
};

// One army. With its own pose art the image is used as drawn; otherwise the statue is posed by
// treatment: a victory leaps and glows, a dejected army sags grey, a defeated one topples.
const Statue = ({ side, mood, height, left, bottom, flip, enter = 'none', delay = 0, instant }) => {
    const frame = useCurrentFrame();
    const { fps, height: H } = useVideoConfig();
    const u = useUnit();
    const pose = side.poses?.[mood];
    const src = pose ?? side.art;
    if (!src) return null;
    const t = frame - delay;
    const rise = instant ? 1 : spring({ frame: t, fps, config: { damping: 15, mass: 0.8 } });
    const slide = enter === 'left' ? interpolate(rise, [0, 1], [-320, 0]) : enter === 'right' ? interpolate(rise, [0, 1], [320, 0]) : 0;
    // The ink outline is baked into the solid cut-out (scripts/build-armies.mjs); filters stay light.
    let transform = '';
    let filter = `brightness(1.08) saturate(1.25)`;
    if (!pose) {
        if (mood === 'victory') {
            const hop = Math.abs(Math.sin(Math.max(0, t) / 5.5)) * 50 * u * Math.exp(-Math.max(0, t) / 50);
            transform = `translateY(${-hop}px) scale(1.06)`;
            filter = `drop-shadow(0 0 ${24 * u}px ${SUN}) brightness(1.15) saturate(1.5)`;
        } else if (mood === 'dejected') {
            const sag = spring({ frame: t - 6, fps, config: { damping: 20 } });
            transform = `translateY(${sag * 4}%) rotate(${(flip ? 1 : -1) * sag * 5}deg) scale(${1 - sag * 0.05})`;
            filter = `grayscale(.55) brightness(.9) saturate(.75)`;
        } else if (mood === 'defeated') {
            const fall = spring({ frame: t - 8, fps, config: { damping: 11, mass: 1.4 } });
            transform = `translateY(${fall * 9}%) rotate(${(flip ? -1 : 1) * fall * 11}deg) scale(${1 - fall * 0.08})`;
            filter = `grayscale(1) sepia(.35) brightness(.72) contrast(1.1)`;
        }
    } else if (mood === 'dejected' || mood === 'defeated') {
        filter = `saturate(.85) brightness(.92)`;
    }
    return (
        <Img
            src={staticFile(src)}
            style={{
                position: 'absolute', height: H * height, left, bottom: H * bottom,
                transform: `translateX(-50%) translateX(${slide}px) ${flip ? 'scaleX(-1)' : ''} ${transform}`,
                transformOrigin: 'bottom center', opacity: interpolate(rise, [0, 0.3], [0, 1], { extrapolateRight: 'clamp' }), filter,
            }}
        />
    );
};

// The comic sound-effect burst: a jagged star with a word in it, slammed in on the drum hit.
const Burst = ({ text, colour = SUN, top, left = '50%', size = 1, delay = 2, tilt = -8 }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const slam = spring({ frame: frame - delay, fps, config: { damping: 9, stiffness: 220 } });
    const points = Array.from({ length: 28 }, (_, i) => {
        const r = i % 2 ? 0.62 + rand(i + text.length) * 0.08 : 1;
        const a = (i / 28) * Math.PI * 2;
        return `${50 + Math.cos(a) * 50 * r},${50 + Math.sin(a) * 42 * r}`;
    }).join(' ');
    const w = 860 * u * size;
    const fontSize = Math.min(150, 1400 / Math.max(5, text.length)) * u * size;
    return (
        <div style={{ position: 'absolute', top, left, width: w, height: w * 0.62, transform: `translateX(-50%) scale(${slam}) rotate(${tilt}deg)`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <svg viewBox="0 0 100 100" preserveAspectRatio="none" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', overflow: 'visible' }}>
                <polygon points={points} fill={INK} transform="translate(2.2 2.6)" />
                <polygon points={points} fill={colour} stroke={INK} strokeWidth="1.6" strokeLinejoin="round" />
            </svg>
            <div style={{ position: 'relative', font: `${fontSize}px/1 Anton`, color: INK, letterSpacing: 2 * u, textShadow: `${4 * u}px ${4 * u}px 0 #fff` }}>{text}</div>
        </div>
    );
};

// The player the caption names, in a round badge ringed in the club colour, sitting on the
// caption's top edge (black and white for a red card).
const Face = ({ photo, name, colour, grey }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const pop = spring({ frame: frame - 3, fps, config: { damping: 11 } });
    return (
        <div style={{ position: 'absolute', right: 26 * u, top: -292 * u, width: 300 * u, textAlign: 'center', transform: `scale(${pop}) rotate(3deg)`, transformOrigin: '50% 100%' }}>
            <Img src={staticFile(photo)} style={{ width: 280 * u, height: 280 * u, borderRadius: '50%', objectFit: 'cover', objectPosition: 'center top', background: PAPER, border: `${9 * u}px solid ${colour}`, outline: `${6 * u}px solid ${INK}`, boxShadow: `${10 * u}px ${10 * u}px 0 ${INK}`, filter: grey ? 'grayscale(1)' : 'none' }} />
            {name && <div style={{ display: 'inline-block', marginTop: -18 * u, position: 'relative', padding: `${4 * u}px ${14 * u}px`, background: INK, color: SUN, font: `${30 * u}px PlexMono`, whiteSpace: 'nowrap' }}>{name}</div>}
        </div>
    );
};

// Caption in a comic speech box with an offset shadow in the side's colour, with the named player's photo.
const Caption = ({ headline, line, colour, delay = 6, photo, name, grey }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const pop = spring({ frame: frame - delay, fps, config: { damping: 13 } });
    return (
        <div style={{ position: 'absolute', left: 52 * u, right: 52 * u, bottom: 96 * u, padding: `${26 * u}px ${32 * u}px`, background: PAPER, border: `${6 * u}px solid ${INK}`, boxShadow: `${14 * u}px ${14 * u}px 0 ${colour}`, transform: `translateY(${interpolate(pop, [0, 1], [60 * u, 0])}px) rotate(-1deg)`, opacity: pop }}>
            {photo && <Face photo={photo} name={name} colour={colour} grey={grey} />}
            <div style={{ font: `${84 * u}px/1.02 Anton`, color: INK, textTransform: 'uppercase' }}>{headline}</div>
            {line && <div style={{ font: `${36 * u}px/1.3 Plex`, color: '#2b2722', marginTop: 12 * u }}>{line}</div>}
        </div>
    );
};

// Scoreboard strip at the top: both clubs in their colours and the score at this moment.
const Board = ({ home, away, homeScore, awayScore, tag }) => {
    const u = useUnit();
    const chip = (side, align) => (
        <div style={{ flex: 1, display: 'flex', alignItems: 'center', gap: 14 * u, justifyContent: align, minWidth: 0 }}>
            {align === 'flex-start' && <div style={{ width: 26 * u, height: 26 * u, background: hue(side), border: `${4 * u}px solid ${INK}` }} />}
            <div style={{ font: `${36 * u}px Anton`, color: INK, textTransform: 'uppercase', overflow: 'hidden', whiteSpace: 'nowrap', textOverflow: 'ellipsis' }}>{side.club}</div>
            {align === 'flex-end' && <div style={{ width: 26 * u, height: 26 * u, background: hue(side), border: `${4 * u}px solid ${INK}` }} />}
        </div>
    );
    return (
        <>
            <div style={{ position: 'absolute', top: 56 * u, left: 52 * u, right: 52 * u, display: 'flex', alignItems: 'center', gap: 18 * u, padding: `${14 * u}px ${22 * u}px`, background: PAPER, border: `${6 * u}px solid ${INK}`, boxShadow: `${10 * u}px ${10 * u}px 0 ${INK}` }}>
                {chip(home, 'flex-start')}
                <div style={{ font: `${60 * u}px/1 Anton`, color: INK, padding: `0 ${18 * u}px`, background: SUN, border: `${4 * u}px solid ${INK}` }}>{homeScore}–{awayScore}</div>
                {chip(away, 'flex-end')}
            </div>
            {tag && <div style={{ position: 'absolute', top: 178 * u, left: 52 * u, padding: `${8 * u}px ${18 * u}px`, background: INK, color: SUN, font: `${28 * u}px PlexMono`, letterSpacing: 2 * u, textTransform: 'uppercase' }}>{tag}</div>}
        </>
    );
};

const Footer = ({ colours }) => {
    const u = useUnit();
    const kente = `repeating-linear-gradient(90deg, ${colours[0]} 0 46px, #d2a052 46px 58px, ${colours[1] ?? PAPER} 58px 86px, ${INK} 86px 94px, #d2a052 94px 106px, ${INK} 106px 112px)`;
    return (
        <>
            <div style={{ position: 'absolute', left: 52 * u, bottom: 40 * u, font: `${22 * u}px PlexMono`, color: PAPER, letterSpacing: 2 * u, textShadow: `0 0 ${8 * u}px ${INK}` }}>MATCHDAY.AFRICA · TOLD BY THE GRIOT</div>
            <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, height: 22 * u, background: kente, borderTop: `${4 * u}px solid ${INK}` }} />
        </>
    );
};

// A white flash wiping off diagonally: the turn of a comic page between scenes.
const Wipe = () => {
    const frame = useCurrentFrame();
    const p = interpolate(frame, [0, 7], [0, 130], { extrapolateRight: 'clamp' });
    if (p >= 130) return null;
    return <AbsoluteFill style={{ background: PAPER, clipPath: `polygon(${p}% 0, 100% 0, 100% 100%, ${p - 30}% 100%)` }} />;
};

const SPLIT_HOME = 'polygon(0 0, 58% 0, 42% 100%, 0 100%)';
const SPLIT_AWAY = 'polygon(58% 0, 100% 0, 100% 100%, 42% 100%)';

// Both armies side by side, each under its own sky (the split is a jagged comic panel line).
const FaceOff = ({ home, away, mood, height = 0.5, bottom = 0.2, enter, instant }) => (
    <>
        <Sky colour={hue(home)} mood={mood.home} x="25%" clip={SPLIT_HOME} />
        <Sky colour={hue(away)} mood={mood.away} x="75%" clip={SPLIT_AWAY} />
        {mood.home === 'dejected' && <Rain clip={SPLIT_HOME} />}
        {mood.away === 'dejected' && <Rain clip={SPLIT_AWAY} />}
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%' }}>
            <polyline points="58,0 54,22 57,30 49,55 52,63 42,100" fill="none" stroke={INK} strokeWidth="10" vectorEffect="non-scaling-stroke" />
        </svg>
        <Statue side={home} mood={mood.home} height={height} left="26%" bottom={bottom} flip enter={enter ? 'left' : 'none'} instant={instant} />
        <Statue side={away} mood={mood.away} height={height} left="74%" bottom={bottom} enter={enter ? 'right' : 'none'} instant={instant} delay={4} />
    </>
);

// Opening: the face-off, both armies in their warrior stance, with the final score on frame 1.
const Title = ({ home, away, competition, beats }) => {
    const frame = useCurrentFrame();
    const u = useUnit();
    const key = keyMoment(beats ?? []);
    const settle = interpolate(frame, [0, 10], [1.05, 1], { extrapolateRight: 'clamp' });
    return (
        <AbsoluteFill>
            <FaceOff home={home} away={away} mood={{ home: 'warrior', away: 'warrior' }} height={0.5} bottom={0.17} instant />
            <div style={{ position: 'absolute', top: 70 * u, left: 0, right: 0, textAlign: 'center' }}>
                <div style={{ display: 'inline-block', padding: `${8 * u}px ${22 * u}px`, background: INK, color: SUN, font: `${28 * u}px PlexMono`, letterSpacing: 3 * u }}>{competition} · FULL TIME</div>
            </div>
            <div style={{ position: 'absolute', top: 150 * u, left: 52 * u, right: 52 * u, padding: `${18 * u}px ${24 * u}px ${8 * u}px`, background: PAPER, border: `${7 * u}px solid ${INK}`, boxShadow: `${14 * u}px ${14 * u}px 0 ${INK}`, transform: `scale(${settle}) rotate(-1.5deg)`, textAlign: 'center' }}>
                <div style={{ display: 'flex', gap: 20 * u }}>
                    {[['home', home], ['away', away]].map(([which, side]) => (
                        <div key={side.club} style={{ flex: 1, minWidth: 0, borderTop: `${12 * u}px solid ${hue(side)}`, paddingTop: 8 * u }}>
                            <div style={{ font: `${40 * u}px Anton`, color: INK, textTransform: 'uppercase', overflow: 'hidden', whiteSpace: 'nowrap', textOverflow: 'ellipsis' }}>{side.club}</div>
                            <div style={{ font: `${24 * u}px PlexMono`, color: '#5a5246', overflow: 'hidden', whiteSpace: 'nowrap', textOverflow: 'ellipsis' }}>{scorers(beats, which) || '\u00a0'}</div>
                        </div>
                    ))}
                </div>
                <div style={{ font: `${300 * u}px/1 Anton`, color: INK }}>
                    {home.score}<span style={{ color: '#d2a052', margin: `0 ${20 * u}px` }}>–</span>{away.score}
                </div>
            </div>
            {key && (
                <div style={{ position: 'absolute', left: 52 * u, right: 52 * u, bottom: 90 * u, padding: `${18 * u}px ${26 * u}px`, background: INK, border: `${5 * u}px solid ${SUN}`, display: 'flex', gap: 22 * u, alignItems: 'center' }}>
                    {key.photo && <Img src={staticFile(key.photo)} style={{ flex: 'none', width: 130 * u, height: 130 * u, borderRadius: '50%', objectFit: 'cover', objectPosition: 'center top', background: PAPER, border: `${6 * u}px solid ${SUN}` }} />}
                    <div style={{ minWidth: 0 }}>
                        <div style={{ font: `${26 * u}px PlexMono`, color: SUN, letterSpacing: 2 * u }}>KEY MOMENT · {key.tag}</div>
                        <div style={{ font: `${58 * u}px/1.02 Anton`, color: PAPER, textTransform: 'uppercase', marginTop: 6 * u }}>{key.headline}</div>
                    </div>
                </div>
            )}
            <Footer colours={home.colours} />
        </AbsoluteFill>
    );
};

const Beat = ({ beat, home, away }) => {
    const u = useUnit();
    const mood = moods(beat);
    const who = focus(beat);
    const hero = who === 'away' ? away : home;
    const foe = who === 'away' ? home : away;
    const heroMood = who ? mood[who] : null;
    const foeMood = who ? mood[who === 'home' ? 'away' : 'home'] : null;
    const frame = useCurrentFrame();
    const flash = beat.type === 'goal' ? interpolate(frame, [0, 3, 12], [0, 0.7, 0], { extrapolateRight: 'clamp' }) : 0;
    const shake = beat.type === 'goal' || beat.type === 'red_card' ? Math.sin(frame * 2.3) * Math.max(0, 10 - frame) * 1.4 * u : 0;
    const burstColour = beat.type === 'red_card' ? '#ff4b3e' : beat.type === 'var' ? '#8fd3ff' : heroMood === 'victory' ? SUN : '#ffffff';
    return (
        <AbsoluteFill style={{ transform: `translate(${shake}px, ${shake * 0.6}px)` }}>
            {who ? (
                <>
                    <Sky colour={hue(hero)} mood={heroMood} x="60%" />
                    {heroMood === 'dejected' && <Rain />}
                    {/* The other army, small, on its own patch of sky in the corner. */}
                    <div style={{ position: 'absolute', left: 0, bottom: 0, width: '46%', height: '62%', clipPath: 'polygon(0 18%, 100% 0, 88% 100%, 0 100%)' }}>
                        <Sky colour={hue(foe)} mood={foeMood} x="40%" />
                    </div>
                    <Statue side={foe} mood={foeMood} height={0.36} left="20%" bottom={0.21} flip enter="left" delay={6} />
                    <Statue side={hero} mood={heroMood} height={0.5} left="64%" bottom={0.19} enter="right" />
                    {heroMood === 'victory' && <Confetti colours={hero.colours} />}
                </>
            ) : (
                <FaceOff home={home} away={away} mood={mood} height={0.44} bottom={0.22} enter />
            )}
            <AbsoluteFill style={{ background: '#ffffff', opacity: flash }} />
            <Board home={home} away={away} homeScore={beat.home_score} awayScore={beat.away_score} tag={beat.tag} />
            {/* In the sky above the other army (top left), clear of the hero's head (it starts at ~31% of the height). */}
            <Burst text={BURSTS[beat.type] ?? beat.type.toUpperCase()} colour={burstColour} top={250 * u} left={who ? '31%' : '50%'} size={who ? 0.6 : 0.58} />
            <Caption headline={beat.headline} line={beat.line} colour={hue(hero)} photo={beat.photo}
                name={beat.player ? beat.player.trim().split(/\s+/).pop() : null} grey={beat.type === 'red_card'} />
            <Footer colours={hero.colours} />
            <Wipe />
        </AbsoluteFill>
    );
};

const Stats = ({ home, away, stats }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const rows = (stats ?? []).filter((s) => s.home !== null && s.away !== null && s.home !== undefined && s.away !== undefined);
    return (
        <AbsoluteFill style={{ background: PAPER }}>
            <AbsoluteFill style={{ background: 'radial-gradient(rgba(0,0,0,.10) 24%, transparent 26%) 0 0 / 16px 16px' }} />
            <Board home={home} away={away} homeScore={home.score} awayScore={away.score} tag="THE NUMBERS" />
            <div style={{ position: 'absolute', top: 360 * u, left: 52 * u, right: 52 * u, display: 'grid', gap: 60 * u }}>
                {(rows.length ? rows : [{ label: 'GOALS', home: home.score, away: away.score }]).map((row, i) => {
                    const grow = spring({ frame: frame - 4 - i * 6, fps, config: { damping: 18 } });
                    const total = Math.max(1, row.home + row.away);
                    return (
                        <div key={row.label}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                                <span style={{ font: `${80 * u}px Anton`, color: INK }}>{row.home}</span>
                                <span style={{ font: `${30 * u}px PlexMono`, color: '#4a443b', letterSpacing: 3 * u }}>{row.label}</span>
                                <span style={{ font: `${80 * u}px Anton`, color: INK }}>{row.away}</span>
                            </div>
                            <div style={{ display: 'flex', height: 40 * u, marginTop: 10 * u, border: `${5 * u}px solid ${INK}`, background: '#fff', boxShadow: `${8 * u}px ${8 * u}px 0 ${INK}` }}>
                                <div style={{ width: `${(row.home / total) * 100 * grow}%`, background: hue(home), borderRight: `${4 * u}px solid ${INK}` }} />
                                <div style={{ flex: 1 }} />
                                <div style={{ width: `${(row.away / total) * 100 * grow}%`, background: hue(away), borderLeft: `${4 * u}px solid ${INK}` }} />
                            </div>
                        </div>
                    );
                })}
            </div>
            <div style={{ position: 'absolute', left: 52 * u, right: 52 * u, bottom: 110 * u, font: `${56 * u}px/1.08 Anton`, color: INK, textTransform: 'uppercase', textAlign: 'center' }}>{home.club}<br /><span style={{ color: '#d2a052' }}>v</span><br />{away.club}</div>
            <Footer colours={home.colours} />
            <Wipe />
        </AbsoluteFill>
    );
};

// The verdict: the winner in victory under confetti, the loser defeated on cracked ground.
// A draw keeps both armies standing.
const Final = ({ home, away, final }) => {
    const u = useUnit();
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const mood = finalMoods(home, away);
    const winner = home.score === away.score ? null : home.score > away.score ? home : away;
    const loser = winner === home ? away : home;
    const end = spring({ frame: frame - 34, fps, config: { damping: 14 } });
    return (
        <AbsoluteFill>
            {winner ? (
                <>
                    <Sky colour={hue(winner)} mood="victory" x="62%" />
                    <div style={{ position: 'absolute', left: 0, bottom: 0, width: '52%', height: '66%', clipPath: 'polygon(0 12%, 100% 0, 84% 100%, 0 100%)' }}>
                        <Sky colour={hue(loser)} mood="defeated" x="40%" />
                    </div>
                    <Cracks left="22%" bottom={0.21} />
                    <Statue side={loser} mood={mood[loser === home ? 'home' : 'away']} height={0.4} left="22%" bottom={0.21} flip />
                    <Statue side={winner} mood="victory" height={0.54} left="66%" bottom={0.2} enter="right" />
                    <Confetti colours={winner.colours} count={56} />
                </>
            ) : (
                <FaceOff home={home} away={away} mood={mood} height={0.5} bottom={0.2} />
            )}
            <Board home={home} away={away} homeScore={home.score} awayScore={away.score} tag="FULL TIME" />
            <Burst text={winner ? 'VICTORY!' : 'HONOURS EVEN'} colour={SUN} top={240 * u} left={winner === away ? '58%' : '50%'} size={0.66} />
            <Caption headline={final.headline} line={final.line} colour={hue(winner ?? home)} delay={4} />
            <div style={{ position: 'absolute', left: '41%', width: '50%', bottom: 400 * u, textAlign: 'center', opacity: end, transform: `scale(${interpolate(end, [0, 1], [0.7, 1])})` }}>
                <div style={{ display: 'inline-block', padding: `${12 * u}px ${28 * u}px`, background: INK, border: `${5 * u}px solid ${SUN}`, font: `${50 * u}px Anton`, color: SUN, letterSpacing: 2 * u }}>MATCHDAY.AFRICA</div>
            </div>
            <Footer colours={(winner ?? home).colours} />
            <Wipe />
        </AbsoluteFill>
    );
};

export const ReelV2 = (props) => {
    const scenes = timelineFor(props);
    return (
        <AbsoluteFill style={{ background: INK }}>
            <style>{fonts}</style>
            {props.audio && <Audio src={staticFile(props.audio)} />}
            {scenes.map((scene, i) => (
                <Sequence key={i} from={scene.from} durationInFrames={scene.duration}>
                    {scene.kind === 'title' && <Title {...props} />}
                    {scene.kind === 'beat' && <Beat beat={scene.beat} home={props.home} away={props.away} />}
                    {scene.kind === 'stats' && <Stats home={props.home} away={props.away} stats={props.stats} />}
                    {scene.kind === 'final' && <Final {...props} />}
                </Sequence>
            ))}
        </AbsoluteFill>
    );
};
