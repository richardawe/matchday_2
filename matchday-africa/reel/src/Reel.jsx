import React from 'react';
import { AbsoluteFill, Audio, Img, Sequence, interpolate, spring, staticFile, useCurrentFrame, useVideoConfig } from 'remotion';
import { buildTimeline, placeBubbles } from './timeline.js';

// Same look as the story cards: black, team-coloured light, crest-free armies,
// Anton headlines, IBM Plex captions and a Kente-style band in team colours.
// Version two adds a moving camera, goal shakes and sword-slashes, armies that rise and slump
// at full time, and speech bubbles the armies trade (BanterService on the site).
// Text stays out of the bottom fifth and the right edge, where TikTok and Instagram put buttons.
const INK = '#070807';
const PAPER = '#e8e1d3';
const GOLD = '#edc781';
const BLOOD = '#d6483c';
const BUBBLE = '#f6f0e5';

const fonts = `
@font-face{font-family:Anton;src:url(${staticFile('fonts/Anton-Regular.ttf')})}
@font-face{font-family:Plex;src:url(${staticFile('fonts/IBMPlexSans.ttf')})}
@font-face{font-family:PlexMono;src:url(${staticFile('fonts/IBMPlexMono-SemiBold.ttf')})}`;

const useUnit = () => useVideoConfig().width / 1080;
const clamp = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' };

const kente = (colours) =>
    `repeating-linear-gradient(90deg, ${colours[0]} 0 46px, #d2a052 46px 58px, ${colours[1] ?? PAPER} 58px 86px, ${INK} 86px 94px, #d2a052 94px 106px, ${INK} 106px 112px)`;

const Glow = ({ colour, x = '50%', y = '40%', size = 85 }) => (
    <AbsoluteFill style={{ background: `radial-gradient(${size}% 55% at ${x} ${y}, ${colour}88 0%, ${colour}22 45%, transparent 75%)` }} />
);

// The camera: a slow push and a drift across the armies, plus a shake when something lands.
const Camera = ({ duration, drift = 1, shake = false, children }) => {
    const frame = useCurrentFrame();
    const u = useUnit();
    const push = interpolate(frame, [0, duration], [1.0, 1.07], clamp);
    const pan = interpolate(frame, [0, duration], [-14 * drift, 14 * drift], clamp) * u;
    const decay = shake ? Math.max(0, 1 - frame / 14) : 0;
    const sx = Math.sin(frame * 2.3) * 16 * u * decay;
    const sy = Math.cos(frame * 3.1) * 11 * u * decay;
    return <AbsoluteFill style={{ transform: `translate(${pan + sx}px, ${sy}px) scale(${push})`, transformOrigin: '50% 60%' }}>{children}</AbsoluteFill>;
};

// One army statue: eases in, can step forward (a goal), rise (victory) or slump (defeat).
const Army = ({ side, height, left, bottom = 0.14, flip, ghost, fallen, enter = 'none', lift = 0, slump = false }) => {
    const frame = useCurrentFrame();
    const { fps, height: H } = useVideoConfig();
    const u = useUnit();
    const rise = spring({ frame, fps, config: { damping: 18, mass: 0.9 } });
    const slide = enter === 'left' ? interpolate(rise, [0, 1], [-260, 0]) : enter === 'right' ? interpolate(rise, [0, 1], [260, 0]) : 0;
    const settle = spring({ frame: frame - 6, fps, config: { damping: 14, mass: 1.1 } });
    const lifted = -lift * u * settle;
    const sag = slump ? settle : 0;
    if (!side.art) return null;
    return (
        <Img
            src={staticFile(side.art)}
            style={{
                position: 'absolute', height: H * height, left, bottom: H * bottom,
                transform: `translateX(-50%) translateX(${slide}px) translateY(${lifted + sag * 46 * u}px) rotate(${sag * (flip ? 4 : -4)}deg) ${flip ? 'scaleX(-1)' : ''}`,
                transformOrigin: 'bottom center',
                opacity: ghost ? 0.22 + 0.1 * (1 - sag) : interpolate(rise, [0, 1], [0, 1]),
                filter: ghost || slump ? `grayscale(1) brightness(${0.6 - sag * 0.2})` : fallen ? 'grayscale(1) sepia(1) hue-rotate(-30deg) saturate(3) brightness(.75)' : 'none',
            }}
        />
    );
};

const Shade = () => (
    <AbsoluteFill style={{ background: 'linear-gradient(180deg, rgba(7,8,7,.85) 0%, transparent 16%, transparent 42%, rgba(7,8,7,.9) 64%, #070807 100%)' }} />
);

// A gold-white streak cutting across the frame on a goal.
const Slash = () => {
    const frame = useCurrentFrame();
    const u = useUnit();
    const x = interpolate(frame, [2, 12], [-1500, 1500], clamp) * u;
    const opacity = interpolate(frame, [2, 4, 10, 13], [0, 1, 1, 0], clamp);
    return (
        <div style={{ position: 'absolute', top: '44%', left: '50%', width: 1700 * u, height: 22 * u, marginLeft: -850 * u, opacity,
            transform: `translateX(${x}px) rotate(-24deg)`, background: `linear-gradient(90deg, transparent, ${GOLD} 35%, #fffaf0 50%, ${GOLD} 65%, transparent)`,
            boxShadow: `0 0 ${40 * u}px ${GOLD}`, borderRadius: 999 }} />
    );
};

const Tag = ({ children, red }) => {
    const u = useUnit();
    return (
        <div style={{ position: 'absolute', top: 64 * u, right: 170 * u, padding: `${12 * u}px ${18 * u}px`, border: `${2 * u}px solid ${red ? BLOOD : GOLD}`, color: red ? BLOOD : GOLD, font: `${30 * u}px PlexMono`, letterSpacing: 2 * u }}>
            {children}
        </div>
    );
};

const Caption = ({ headline, line, score, delay = 4 }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const slam = spring({ frame: frame - delay, fps, config: { damping: 12, stiffness: 180 } });
    const fade = interpolate(frame, [delay + 8, delay + 18], [0, 1], clamp);
    const punch = spring({ frame: frame - delay - 10, fps, config: { damping: 8, stiffness: 220 } });
    return (
        <div style={{ position: 'absolute', left: 64 * u, right: 170 * u, bottom: 400 * u }}>
            <div style={{ font: `${120 * u}px/1.02 Anton`, color: PAPER, textTransform: 'uppercase', transform: `scale(${interpolate(slam, [0, 1], [1.25, 1])})`, transformOrigin: 'left bottom', opacity: slam }}>{headline}</div>
            {line && <div style={{ font: `${40 * u}px/1.35 Plex`, color: '#d6cfc0', marginTop: 18 * u, opacity: fade }}>{line}</div>}
            {score && <div style={{ font: `${44 * u}px PlexMono`, color: GOLD, marginTop: 18 * u, opacity: fade, transform: `scale(${interpolate(punch, [0, 1], [1.5, 1])})`, transformOrigin: 'left center' }}>{score}</div>}
        </div>
    );
};

const Footer = ({ colours }) => {
    const u = useUnit();
    return (
        <>
            <div style={{ position: 'absolute', left: 64 * u, bottom: 56 * u, font: `${24 * u}px PlexMono`, color: '#a39c8f', letterSpacing: 2 * u }}>MATCHDAY.AFRICA · TOLD BY THE GRIOT</div>
            <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, height: 22 * u, background: kente(colours) }} />
        </>
    );
};

// A speech bubble: pops in, types its line, tail pointing down at the army that speaks.
// `at` is 'left' or 'right' of the frame, depending on where that army stands in the scene.
const Bubble = ({ bubble, at, row = 0 }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const pop = spring({ frame, fps, config: { damping: 11, stiffness: 200, mass: 0.7 } });
    const typing = Math.max(10, Math.min(bubble.duration * 0.8, bubble.text.length * 1.5));
    const shown = Math.round(interpolate(frame, [2, 2 + typing], [0, bubble.text.length], clamp));
    const left = at === 'left';
    return (
        <div style={{ position: 'absolute', top: (520 + row * 210) * u, [left ? 'left' : 'right']: (left ? 56 : 170) * u, maxWidth: 660 * u,
            padding: `${24 * u}px ${30 * u}px`, background: BUBBLE, color: '#171812', borderRadius: 34 * u, boxShadow: `0 ${14 * u}px ${44 * u}px rgba(0,0,0,.55)`,
            font: `700 ${44 * u}px/1.25 Plex`, transform: `scale(${pop}) rotate(${(1 - pop) * (left ? -6 : 6)}deg)`, transformOrigin: left ? 'left bottom' : 'right bottom' }}>
            {/* The full line, invisible, holds the bubble's size while the visible copy types out. */}
            <span style={{ visibility: 'hidden' }}>{bubble.text}</span>
            <span style={{ position: 'absolute', left: 30 * u, right: 30 * u, top: 24 * u }}>{bubble.text.slice(0, shown)}</span>
            <div style={{ position: 'absolute', bottom: -14 * u, [left ? 'left' : 'right']: 70 * u, width: 34 * u, height: 34 * u, background: BUBBLE, transform: 'rotate(45deg)' }} />
        </div>
    );
};

// The bubbles of one scene, placed beside the army that speaks them.
const Bubbles = ({ list, sceneFrom, sides }) => (
    <>
        {list.map((b, i) => (
            <Sequence key={i} from={b.from - sceneFrom} durationInFrames={b.until - b.from}>
                <Bubble bubble={b} at={sides[b.side]} row={i} />
            </Sequence>
        ))}
    </>
);

const Title = ({ home, away, title, competition, duration, bubbles, sceneFrom }) => {
    const u = useUnit();
    return (
        <AbsoluteFill style={{ background: INK }}>
            <Camera duration={duration}>
                <Glow colour={home.colours[0]} x="25%" size={60} />
                <Glow colour={away.colours[0]} x="75%" size={60} />
                <Army side={home} height={0.58} left="27%" bottom={0.22} flip enter="left" />
                <Army side={away} height={0.58} left="73%" bottom={0.22} enter="right" />
            </Camera>
            <Shade />
            <div style={{ position: 'absolute', top: 72 * u, left: 64 * u, font: `${26 * u}px PlexMono`, color: '#a39c8f', letterSpacing: 3 * u }}>{competition}</div>
            <Tag>FULL TIME</Tag>
            <Caption headline={title.headline} line={title.line} delay={10} />
            <Bubbles list={bubbles} sceneFrom={sceneFrom} sides={{ home: 'left', away: 'right' }} />
            <Footer colours={home.colours} />
        </AbsoluteFill>
    );
};

const Beat = ({ beat, home, away, duration, index, bubbles, sceneFrom }) => {
    const hero = beat.side === 'away' ? away : home;
    const foe = beat.side === 'away' ? home : away;
    const both = !beat.side;
    const red = beat.type === 'red_card';
    const goal = beat.type === 'goal';
    const frame = useCurrentFrame();
    // A goal flashes the scoring side's colour on the drum hit; a red card flashes red.
    const flash = goal || red ? interpolate(frame, [0, 3, 14], [0, red ? 0.5 : 0.55, 0], clamp) : 0;
    const sides = both ? { home: 'left', away: 'right' } : { [beat.side]: 'right', [beat.side === 'home' ? 'away' : 'home']: 'left' };
    return (
        <AbsoluteFill style={{ background: INK }}>
            <Camera duration={duration} drift={index % 2 ? -1 : 1} shake={goal || red}>
                {both ? (
                    <>
                        <Glow colour={home.colours[0]} x="25%" size={60} />
                        <Glow colour={away.colours[0]} x="75%" size={60} />
                        <Army side={home} height={0.56} left="27%" bottom={0.24} flip />
                        <Army side={away} height={0.56} left="73%" bottom={0.24} />
                    </>
                ) : (
                    <>
                        <Glow colour={red ? '#7a1d16' : hero.colours[0]} x="58%" />
                        <Army side={foe} height={0.48} left="16%" bottom={0.28} flip ghost />
                        <Army side={hero} height={0.72} left="58%" bottom={0.18} fallen={red} enter="right" lift={goal ? 26 : 0} />
                    </>
                )}
            </Camera>
            {goal && <Slash />}
            <AbsoluteFill style={{ background: red ? BLOOD : hero.colours[0], opacity: flash, mixBlendMode: 'screen' }} />
            <Shade />
            <Tag red={red}>{beat.tag}</Tag>
            <Caption headline={beat.headline} line={beat.line} score={`${home.club}  ${beat.home_score}–${beat.away_score}  ${away.club}`} />
            <Bubbles list={bubbles} sceneFrom={sceneFrom} sides={sides} />
            <Footer colours={hero.colours} />
        </AbsoluteFill>
    );
};

const Stats = ({ home, away, stats }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const rows = stats.filter((s) => s.home !== null && s.away !== null);
    return (
        <AbsoluteFill style={{ background: INK }}>
            <Glow colour={home.colours[0]} x="20%" y="30%" size={55} />
            <Glow colour={away.colours[0]} x="80%" y="30%" size={55} />
            <div style={{ position: 'absolute', top: 72 * u, left: 64 * u, font: `${26 * u}px PlexMono`, color: GOLD, letterSpacing: 3 * u }}>THE NUMBERS</div>
            <div style={{ position: 'absolute', top: 360 * u, left: 64 * u, right: 170 * u, display: 'grid', gap: 64 * u }}>
                {(rows.length ? rows : [{ label: 'GOALS', home: home.score, away: away.score }]).map((row, i) => {
                    const grow = spring({ frame: frame - i * 6, fps, config: { damping: 20 } });
                    const total = Math.max(1, row.home + row.away);
                    return (
                        <div key={row.label}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', font: `${34 * u}px PlexMono`, color: PAPER }}>
                                <span>{row.home}</span><span style={{ color: '#a39c8f', letterSpacing: 3 * u }}>{row.label}</span><span>{row.away}</span>
                            </div>
                            <div style={{ display: 'flex', height: 22 * u, marginTop: 16 * u, background: '#1d1c18' }}>
                                <div style={{ width: `${(row.home / total) * 100 * grow}%`, background: home.colours[0] }} />
                                <div style={{ flex: 1 }} />
                                <div style={{ width: `${(row.away / total) * 100 * grow}%`, background: away.colours[0] }} />
                            </div>
                        </div>
                    );
                })}
            </div>
            <Caption headline={`${home.army} v ${away.army}`} line="" />
            <Footer colours={home.colours} />
        </AbsoluteFill>
    );
};

// The last seconds point people home: the full story lives on the match page.
const EndCard = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const show = spring({ frame: frame - 40, fps, config: { damping: 14 } });
    return (
        <div style={{ position: 'absolute', top: 260 * u, left: 40 * u, right: 170 * u, padding: `${26 * u}px ${24 * u}px`, background: 'rgba(7,8,7,.85)', borderLeft: `${6 * u}px solid ${GOLD}`, opacity: show, transform: `translateY(${interpolate(show, [0, 1], [30, 0])}px)` }}>
            <div style={{ font: `${30 * u}px PlexMono`, color: PAPER, letterSpacing: 3 * u }}>FULL STORY, STATS &amp; PREDICTIONS</div>
            <div style={{ font: `${100 * u}px/1.05 Anton`, color: GOLD, marginTop: 10 * u }}>MATCHDAY.AFRICA</div>
        </div>
    );
};

const Final = ({ home, away, final, duration, bubbles, sceneFrom }) => {
    const winner = home.score === away.score ? null : home.score > away.score ? home : away;
    const loser = winner === home ? away : home;
    const winnerSide = winner === home ? 'home' : 'away';
    const u = useUnit();
    const sides = winner ? { [winnerSide]: 'right', [winnerSide === 'home' ? 'away' : 'home']: 'left' } : { home: 'left', away: 'right' };
    return (
        <AbsoluteFill style={{ background: INK }}>
            <Camera duration={duration}>
                {winner ? (
                    <>
                        <Glow colour={winner.colours[0]} x="55%" />
                        <Army side={loser} height={0.46} left="16%" bottom={0.3} flip ghost slump />
                        <Army side={winner} height={0.74} left="56%" bottom={0.17} lift={40} />
                    </>
                ) : (
                    <>
                        <Glow colour={home.colours[0]} x="25%" size={60} />
                        <Glow colour={away.colours[0]} x="75%" size={60} />
                        <Army side={home} height={0.56} left="27%" bottom={0.24} flip />
                        <Army side={away} height={0.56} left="73%" bottom={0.24} />
                    </>
                )}
            </Camera>
            <Shade />
            <Tag>FULL TIME</Tag>
            <div style={{ position: 'absolute', top: 72 * u, left: 64 * u, right: 440 * u, font: `${26 * u}px/1.4 PlexMono`, color: '#a39c8f', letterSpacing: 3 * u }}>{home.club.toUpperCase()} {home.score}–{away.score} {away.club.toUpperCase()}</div>
            <Caption headline={final.headline} line={final.line} delay={3} />
            <Bubbles list={bubbles} sceneFrom={sceneFrom} sides={sides} />
            <EndCard />
            <Footer colours={(winner ?? home).colours} />
        </AbsoluteFill>
    );
};

export const Reel = (props) => {
    const scenes = buildTimeline(props.beats, props.bubbles ?? []);
    const placed = placeBubbles(props.beats, props.bubbles ?? []);
    const inScene = (scene) => placed.filter((b) => b.from >= scene.from && b.from < scene.from + scene.duration);
    let beatIndex = 0;
    return (
        <AbsoluteFill style={{ background: INK }}>
            <style>{fonts}</style>
            {props.audio && <Audio src={staticFile(props.audio)} />}
            {scenes.map((scene, i) => (
                <Sequence key={i} from={scene.from} durationInFrames={scene.duration}>
                    {scene.kind === 'title' && <Title {...props} duration={scene.duration} bubbles={inScene(scene)} sceneFrom={scene.from} />}
                    {scene.kind === 'beat' && <Beat beat={scene.beat} home={props.home} away={props.away} duration={scene.duration} index={beatIndex++} bubbles={inScene(scene)} sceneFrom={scene.from} />}
                    {scene.kind === 'stats' && <Stats home={props.home} away={props.away} stats={props.stats ?? []} />}
                    {scene.kind === 'final' && <Final {...props} duration={scene.duration} bubbles={inScene(scene)} sceneFrom={scene.from} />}
                </Sequence>
            ))}
        </AbsoluteFill>
    );
};
