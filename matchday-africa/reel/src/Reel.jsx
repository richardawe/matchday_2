import React from 'react';
import { AbsoluteFill, Audio, Img, Sequence, interpolate, spring, staticFile, useCurrentFrame, useVideoConfig } from 'remotion';
import { buildTimeline } from './timeline.js';

// Same look as the story cards: black, team-coloured light, crest-free armies,
// Anton headlines, IBM Plex captions and a Kente-style band in team colours.
const INK = '#070807';
const PAPER = '#e8e1d3';
const GOLD = '#edc781';
const BLOOD = '#d6483c';

const fonts = `
@font-face{font-family:Anton;src:url(${staticFile('fonts/Anton-Regular.ttf')})}
@font-face{font-family:Plex;src:url(${staticFile('fonts/IBMPlexSans.ttf')})}
@font-face{font-family:PlexMono;src:url(${staticFile('fonts/IBMPlexMono-SemiBold.ttf')})}`;

const useUnit = () => useVideoConfig().width / 1080;

const kente = (colours) =>
    `repeating-linear-gradient(90deg, ${colours[0]} 0 46px, #d2a052 46px 58px, ${colours[1] ?? PAPER} 58px 86px, ${INK} 86px 94px, #d2a052 94px 106px, ${INK} 106px 112px)`;

const Glow = ({ colour, x = '50%', y = '40%', size = 85 }) => (
    <AbsoluteFill style={{ background: `radial-gradient(${size}% 55% at ${x} ${y}, ${colour}88 0%, ${colour}22 45%, transparent 75%)` }} />
);

// One army statue, eased in and drifting slowly (a Ken Burns push).
const Army = ({ side, height, left, bottom = 0.14, flip, ghost, fallen, enter = 'none', duration }) => {
    const frame = useCurrentFrame();
    const { fps, height: H } = useVideoConfig();
    const rise = spring({ frame, fps, config: { damping: 18, mass: 0.9 } });
    const slide = enter === 'left' ? interpolate(rise, [0, 1], [-260, 0]) : enter === 'right' ? interpolate(rise, [0, 1], [260, 0]) : 0;
    const push = interpolate(frame, [0, duration], [1, 1.07], { extrapolateRight: 'clamp' });
    if (!side.art) return null;
    return (
        <Img
            src={staticFile(side.art)}
            style={{
                position: 'absolute', height: H * height, left, bottom: H * bottom,
                transform: `translateX(-50%) translateX(${slide}px) scale(${push}) ${flip ? 'scaleX(-1)' : ''}`,
                transformOrigin: 'bottom center',
                opacity: ghost ? 0.22 : interpolate(rise, [0, 1], [0, 1]),
                filter: ghost ? 'grayscale(1) brightness(.6)' : fallen ? 'grayscale(1) sepia(1) hue-rotate(-30deg) saturate(3) brightness(.75)' : 'none',
            }}
        />
    );
};

const Shade = () => (
    <AbsoluteFill style={{ background: 'linear-gradient(180deg, rgba(7,8,7,.85) 0%, transparent 16%, transparent 48%, rgba(7,8,7,.92) 72%, #070807 100%)' }} />
);

const Tag = ({ children, red }) => {
    const u = useUnit();
    return (
        <div style={{ position: 'absolute', top: 64 * u, right: 64 * u, padding: `${12 * u}px ${18 * u}px`, border: `${2 * u}px solid ${red ? BLOOD : GOLD}`, color: red ? BLOOD : GOLD, font: `${30 * u}px PlexMono`, letterSpacing: 2 * u }}>
            {children}
        </div>
    );
};

const Caption = ({ headline, line, score, delay = 4 }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const slam = spring({ frame: frame - delay, fps, config: { damping: 12, stiffness: 180 } });
    const fade = interpolate(frame, [delay + 8, delay + 18], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
    return (
        <div style={{ position: 'absolute', left: 64 * u, right: 64 * u, bottom: 130 * u }}>
            <div style={{ font: `${128 * u}px/1.02 Anton`, color: PAPER, textTransform: 'uppercase', transform: `scale(${interpolate(slam, [0, 1], [1.25, 1])})`, transformOrigin: 'left bottom', opacity: slam }}>{headline}</div>
            {line && <div style={{ font: `${40 * u}px/1.35 Plex`, color: '#d6cfc0', marginTop: 18 * u, opacity: fade }}>{line}</div>}
            {score && <div style={{ font: `${40 * u}px PlexMono`, color: GOLD, marginTop: 18 * u, opacity: fade }}>{score}</div>}
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

const Title = ({ home, away, title, competition, duration }) => {
    const u = useUnit();
    return (
        <AbsoluteFill style={{ background: INK }}>
            <Glow colour={home.colours[0]} x="25%" size={60} />
            <Glow colour={away.colours[0]} x="75%" size={60} />
            <Army side={home} height={0.58} left="27%" bottom={0.2} flip enter="left" duration={duration} />
            <Army side={away} height={0.58} left="73%" bottom={0.2} enter="right" duration={duration} />
            <Shade />
            <div style={{ position: 'absolute', top: 72 * u, left: 64 * u, font: `${26 * u}px PlexMono`, color: '#a39c8f', letterSpacing: 3 * u }}>{competition}</div>
            <Tag>FULL TIME</Tag>
            <Caption headline={title.headline} line={title.line} delay={10} />
            <Footer colours={home.colours} />
        </AbsoluteFill>
    );
};

const Beat = ({ beat, home, away, duration }) => {
    const hero = beat.side === 'away' ? away : home;
    const foe = beat.side === 'away' ? home : away;
    const both = !beat.side;
    const red = beat.type === 'red_card';
    const frame = useCurrentFrame();
    // A goal flashes the scoring side's colour for a few frames, on the drum hit.
    const flash = beat.type === 'goal' ? interpolate(frame, [0, 3, 14], [0, 0.55, 0], { extrapolateRight: 'clamp' }) : 0;
    return (
        <AbsoluteFill style={{ background: INK }}>
            {both ? (
                <>
                    <Glow colour={home.colours[0]} x="25%" size={60} />
                    <Glow colour={away.colours[0]} x="75%" size={60} />
                    <Army side={home} height={0.56} left="27%" bottom={0.22} flip duration={duration} />
                    <Army side={away} height={0.56} left="73%" bottom={0.22} duration={duration} />
                </>
            ) : (
                <>
                    <Glow colour={red ? '#7a1d16' : hero.colours[0]} x="58%" />
                    <Army side={foe} height={0.48} left="16%" bottom={0.27} flip ghost duration={duration} />
                    <Army side={hero} height={0.72} left="58%" bottom={0.17} fallen={red} enter="right" duration={duration} />
                </>
            )}
            <AbsoluteFill style={{ background: hero.colours[0], opacity: flash, mixBlendMode: 'screen' }} />
            <Shade />
            <Tag red={red}>{beat.tag}</Tag>
            <Caption headline={beat.headline} line={beat.line} score={`${home.club}  ${beat.home_score}–${beat.away_score}  ${away.club}`} />
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
            <div style={{ position: 'absolute', top: 360 * u, left: 64 * u, right: 64 * u, display: 'grid', gap: 64 * u }}>
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

// The last second points people home: the full story lives on the match page.
const EndCard = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const u = useUnit();
    const show = spring({ frame: frame - 18, fps, config: { damping: 14 } });
    return (
        <div style={{ position: 'absolute', top: 280 * u, left: 40 * u, right: 40 * u, padding: `${26 * u}px ${24 * u}px`, background: 'rgba(7,8,7,.82)', borderLeft: `${6 * u}px solid ${GOLD}`, opacity: show, transform: `translateY(${interpolate(show, [0, 1], [30, 0])}px)` }}>
            <div style={{ font: `${30 * u}px PlexMono`, color: PAPER, letterSpacing: 3 * u }}>FULL STORY, STATS &amp; PREDICTIONS</div>
            <div style={{ font: `${104 * u}px/1.05 Anton`, color: GOLD, marginTop: 10 * u }}>MATCHDAY.AFRICA</div>
        </div>
    );
};

const Final = ({ home, away, final, duration }) => {
    const winner = home.score === away.score ? null : home.score > away.score ? home : away;
    const loser = winner === home ? away : home;
    const u = useUnit();
    return (
        <AbsoluteFill style={{ background: INK }}>
            {winner ? (
                <>
                    <Glow colour={winner.colours[0]} x="55%" />
                    <Army side={loser} height={0.46} left="16%" bottom={0.28} flip ghost duration={duration} />
                    <Army side={winner} height={0.74} left="56%" bottom={0.16} duration={duration} />
                </>
            ) : (
                <>
                    <Glow colour={home.colours[0]} x="25%" size={60} />
                    <Glow colour={away.colours[0]} x="75%" size={60} />
                    <Army side={home} height={0.56} left="27%" bottom={0.22} flip duration={duration} />
                    <Army side={away} height={0.56} left="73%" bottom={0.22} duration={duration} />
                </>
            )}
            <Shade />
            <Tag>FULL TIME</Tag>
            <div style={{ position: 'absolute', top: 72 * u, left: 64 * u, font: `${26 * u}px PlexMono`, color: '#a39c8f', letterSpacing: 3 * u }}>{home.club.toUpperCase()} {home.score}–{away.score} {away.club.toUpperCase()}</div>
            <Caption headline={final.headline} line={final.line} delay={3} />
            <EndCard />
            <Footer colours={(winner ?? home).colours} />
        </AbsoluteFill>
    );
};

export const Reel = (props) => {
    const scenes = buildTimeline(props.beats);
    return (
        <AbsoluteFill style={{ background: INK }}>
            <style>{fonts}</style>
            {props.audio && <Audio src={staticFile(props.audio)} />}
            {scenes.map((scene, i) => (
                <Sequence key={i} from={scene.from} durationInFrames={scene.duration}>
                    {scene.kind === 'title' && <Title {...props} duration={scene.duration} />}
                    {scene.kind === 'beat' && <Beat beat={scene.beat} home={props.home} away={props.away} duration={scene.duration} />}
                    {scene.kind === 'stats' && <Stats home={props.home} away={props.away} stats={props.stats ?? []} />}
                    {scene.kind === 'final' && <Final {...props} duration={scene.duration} />}
                </Sequence>
            ))}
        </AbsoluteFill>
    );
};
