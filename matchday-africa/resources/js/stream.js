/**
 * Matchday Live: fills the 1080×1920 stream screen (resources/views/stream.blade.php) from
 * /stream/feed every few seconds, in place, so the stream never shows a reload. Goals and red cards
 * take the screen for a few seconds with a drum call; a goal ruled out shows a short banner.
 * ?test=1 plays a made-up match day instead of the feed (resources/js/stream/fake.js); add &at=SECONDS
 * to start part-way through (goal at 12, red card at 30, goal ruled out at 84, between matches at 120).
 */
import { createMoments } from './stream/moments.js';
import { createSound } from './stream/sound.js';
import { fakeSnapshot } from './stream/fake.js';

const stage = document.getElementById('stage');
const $ = (id) => document.getElementById(id);
const params = new URLSearchParams(location.search);
const TEST = params.get('test') === '1';
const POLL = Math.max(2, Number(stage.dataset.poll) || 3) * 1000;
const MOMENT = (Number(stage.dataset.moment) || 8) * 1000;
const ROTATE = (Number(stage.dataset.rotate) || 20) * 1000;
const PIN = 60 * 1000;
// ?test=1&at=120 starts the made-up match day 120 seconds in (between matches).
const startedAt = Date.now() - (Number(params.get('at')) || 0) * 1000;

const diff = createMoments();
const sound = createSound();
let snapshot = null;
let clockOffset = 0; // server time minus this machine's time
let lastGood = Date.now();
let featuredId = null;
let featuredSince = 0;
let pinned = null; // { id, until }
const queue = [];
let showing = null; // { moment, until }
const voiced = new Set();

/* ---------- helpers ---------- */
function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
}
function tile(node, team) {
    node.textContent = team?.short ?? '';
    node.style.background = team?.colour ?? '#4a443b';
    node.style.color = team?.ink ?? '#ffffff';
    return node;
}
function setText(id, text) {
    const node = $(id);
    if (node.textContent !== (text ?? '')) node.textContent = text ?? '';
}
function setImg(img, src, alt) {
    if (!src) { img.hidden = true; return; }
    img.hidden = false;
    if (img.getAttribute('src') !== src) img.src = src;
    img.alt = alt ?? '';
}
const sideTeam = (match, side) => (side === 'home' ? match.home : match.away);
const now = () => Date.now() + clockOffset;

/* ---------- fitting the stage to the window ---------- */
function fit() {
    const s = Math.min(window.innerWidth / 1080, window.innerHeight / 1920);
    const x = (window.innerWidth - 1080 * s) / 2;
    const y = (window.innerHeight - 1920 * s) / 2;
    stage.style.transform = `translate(${x}px, ${y}px) scale(${s})`;
}
window.addEventListener('resize', fit);
fit();

/* ---------- sound button ---------- */
const soundButton = $('sound');
function checkSound() { soundButton.hidden = !sound.needsTap(); }
soundButton.addEventListener('click', () => sound.unlock().then(checkSound));
document.addEventListener('pointerdown', () => sound.unlock().then(checkSound), { once: true });
sound.unlock().catch(() => {}).finally(() => setTimeout(checkSound, 300));

/* ---------- live view ---------- */
function featured(live) {
    if (!live.length) return null;
    const t = Date.now();
    if (pinned && pinned.until > t && live.some((m) => m.id === pinned.id)) return live.find((m) => m.id === pinned.id);
    let current = live.find((m) => m.id === featuredId);
    if (!current || (live.length > 1 && t - featuredSince >= ROTATE)) {
        const index = current ? (live.indexOf(current) + 1) % live.length : 0;
        current = live[index];
        featuredSince = t;
    }
    featuredId = current.id;
    return current;
}

function scorers(match, side) {
    return (match.goals ?? []).filter((g) => g.side === side && g.scorer)
        .map((g) => `${g.scorer.split(' ').slice(-1)[0]} ${g.minute ?? ''}${g.own_goal ? ' (og)' : ''}${g.penalty ? ' (pen)' : ''}`.trim()).join(' · ');
}

let lastScore = {};
function renderLive(match, live) {
    $('view-live').hidden = false;
    $('view-idle').hidden = true;
    setText('m-competition', [match.competition, match.venue].filter(Boolean).join(' · '));
    setText('m-clock', match.clock);
    tile($('m-home-tile'), match.home);
    tile($('m-away-tile'), match.away);
    setText('m-home-name', match.home.name);
    setText('m-away-name', match.away.name);
    const [h, a] = match.score ?? [0, 0];
    for (const [id, value, key] of [['m-home-score', h, `${match.id}h`], ['m-away-score', a, `${match.id}a`]]) {
        const node = $(id);
        if (node.textContent !== String(value)) {
            node.textContent = String(value);
            if (lastScore[key] !== undefined && lastScore[key] !== value) {
                node.classList.remove('st-pop');
                void node.offsetWidth;
                node.classList.add('st-pop');
            }
        }
        lastScore[key] = value;
    }
    setText('m-home-scorers', scorers(match, 'home'));
    setText('m-away-scorers', scorers(match, 'away'));

    const arena = $('m-arena');
    arena.style.background = `linear-gradient(90deg, ${match.home.colour} 0 50%, ${match.away.colour} 50% 100%)`;
    setImg($('m-home-art'), match.home.art, match.home.army ? `${match.home.name}'s army, the ${match.home.army}` : '');
    setImg($('m-away-art'), match.away.art, match.away.army ? `${match.away.name}'s army, the ${match.away.army}` : '');
    setText('m-home-army', match.home.army ?? match.home.name);
    setText('m-away-army', match.away.army ?? match.away.name);
    arena.querySelectorAll('.st-tile').forEach((n) => n.remove());
    if (!match.home.art) { const t = tile(el('span', 'st-tile'), match.home); t.style.left = '110px'; arena.append(t); }
    if (!match.away.art) { const t = tile(el('span', 'st-tile'), match.away); t.style.right = '110px'; arena.append(t); }

    const griot = match.griot;
    $('m-griot').hidden = !griot;
    if (griot) {
        setText('m-griot-clock', griot.clock ?? '');
        setText('m-griot-head', griot.headline);
        setText('m-griot-line', griot.line);
    }

    const stats = $('m-stats');
    const rows = [['Possession', match.stats?.possession, '%'], ['Shots', match.stats?.shots, '']].filter(([, pair]) => pair);
    stats.hidden = !rows.length;
    const key = JSON.stringify(rows);
    if (stats.dataset.key !== key) {
        stats.dataset.key = key;
        stats.replaceChildren(...rows.map(([label, [x, y], unit]) => {
            const block = el('div', 'st-stat');
            const row = el('div', 'st-stat-row');
            row.append(el('span', null, `${x}${unit}`), el('span', null, label), el('span', null, `${y}${unit}`));
            const bar = el('div', 'st-bar');
            const left = el('div');
            const right = el('div');
            const share = x + y > 0 ? (x / (x + y)) * 100 : 50;
            left.style.width = `${share}%`;
            left.style.background = match.home.colour;
            right.style.flex = '1';
            right.style.background = match.away.colour;
            bar.append(left, right);
            block.append(row, bar);
            return block;
        }));
    }

    if (match.call) {
        setText('m-cta-head', 'BEAT THE GRIOT');
        setText('m-cta-line', `The Griot called this ${match.call[0]}–${match.call[1]}. Call your score.`);
    } else {
        setText('m-cta-head', 'EVERY MATCH, LIVE');
        setText('m-cta-line', 'Scores, the Griot and full-time reels.');
    }

    const dots = $('m-dots');
    dots.replaceChildren(...(live.length > 1 ? live.map((m) => el('span', m.id === match.id ? 'on' : '')) : []));
}

/* ---------- between matches ---------- */
function two(n) { return String(Math.max(0, n)).padStart(2, '0'); }
function renderCountdown() {
    const next = snapshot?.next;
    const node = $('i-countdown');
    const units = node.nextElementSibling;
    if (!next) { node.hidden = true; units.hidden = true; return; }
    const left = Math.round((new Date(next.kickoff).getTime() - now()) / 1000);
    node.hidden = false;
    if (left <= 0) {
        node.textContent = 'KICK-OFF SOON';
        node.classList.add('st-soon');
        units.hidden = true;
        return;
    }
    node.classList.remove('st-soon');
    units.hidden = false;
    node.textContent = `${two(Math.floor(left / 3600))}:${two(Math.floor((left % 3600) / 60))}:${two(left % 60)}`;
}

function fixtureRow(f, finished) {
    const row = el('div', `st-row${finished ? ' st-ft' : ''}`);
    const text = finished && f.score ? `${f.home.name} ${f.score[0]}–${f.score[1]} ${f.away.name}` : `${f.home.name} v ${f.away.name}`;
    row.append(el('span', null, text), el('span', null, finished ? 'FT' : f.kickoff_lagos));
    return row;
}

function renderIdle(snap) {
    $('view-live').hidden = true;
    $('view-idle').hidden = false;
    const next = snap.next;
    if (next) {
        const day = new Date(next.kickoff).toLocaleDateString('en-GB', { weekday: 'long', timeZone: 'Africa/Lagos' });
        const today = new Date(now()).toLocaleDateString('en-GB', { weekday: 'long', timeZone: 'Africa/Lagos' });
        setText('i-next-label', `NEXT KICK-OFF · ${day === today ? '' : `${day.toUpperCase()} `}${next.kickoff_lagos} LAGOS`);
        tile($('i-home-tile'), next.home);
        tile($('i-away-tile'), next.away);
        setText('i-home-name', next.home.name);
        setText('i-away-name', next.away.name);
    } else {
        setText('i-next-label', 'NO MATCHES SCHEDULED THIS WEEK');
        setText('i-home-name', '');
        setText('i-away-name', '');
    }
    $('i-next').querySelector('.st-next-teams').hidden = !next;
    renderCountdown();

    const later = snap.later ?? [];
    $('i-later-head').hidden = !later.length;
    $('i-later').replaceChildren(...later.map((f) => fixtureRow(f, false)));
    const results = snap.results ?? [];
    $('i-results-head').hidden = !results.length;
    $('i-results').replaceChildren(...results.map((f) => fixtureRow(f, true)));

    // The newest full-time reel; without one, the last result's winning army (or the home side's).
    const reel = snap.reel;
    const last = results.find((f) => f.score && (f.home.art || f.away.art));
    const box = $('i-reel');
    const video = $('i-reel-video');
    const art = $('i-reel-art');
    box.hidden = !reel && !last;
    box.parentElement.style.gridTemplateColumns = box.hidden ? 'minmax(0, 1fr)' : '';
    video.hidden = !reel;
    art.hidden = !!reel;
    if (reel) {
        setText('i-reel-label', 'NOW PLAYING · FULL-TIME REEL');
        if (video.getAttribute('src') !== reel.url) {
            video.src = reel.url;
            video.play().catch(() => {});
        }
        box.style.background = '';
        setText('i-reel-score', reel.label);
    } else if (last) {
        if (!video.paused) video.pause();
        const [h, a] = last.score;
        const side = h < a && last.away.art ? last.away : (last.home.art ? last.home : last.away);
        setText('i-reel-label', 'LAST RESULT');
        setImg(art, side.art, side.army ? `${side.name}'s army, the ${side.army}` : side.name);
        box.style.background = side.colour;
        setText('i-reel-score', `${last.home.short} ${h}–${a} ${last.away.short}`);
    }
}

/* ---------- ticker ---------- */
function renderTicker(snap, current) {
    const items = [];
    for (const m of snap.live ?? []) {
        if (current && m.id === current.id) continue;
        items.push([`${m.home.short} ${m.score?.[0] ?? 0}–${m.score?.[1] ?? 0} ${m.away.short}`, m.clock]);
    }
    if (current) for (const f of snap.results ?? []) items.push([`${f.home.short} ${f.score[0]}–${f.score[1]} ${f.away.short}`, 'FT']);
    const ticker = $('ticker');
    ticker.hidden = !items.length;
    const key = JSON.stringify(items);
    if (ticker.dataset.key === key) return;
    ticker.dataset.key = key;
    ticker.replaceChildren(...items.map(([text, tag]) => {
        const span = el('span', null, `${text} `);
        span.append(el('b', null, tag));
        return span;
    }));
}

/* ---------- goal and red-card moments ---------- */
function strip(match, highlight) {
    const box = el('div', 'st-strip');
    const home = el('div', 'st-side-row');
    home.append(tile(el('span', 'st-tile'), match.home), el('span', 'st-team', match.home.name));
    const away = el('div', 'st-side-row st-side-row-away');
    away.append(el('span', 'st-team', match.away.name), tile(el('span', 'st-tile'), match.away));
    const score = el('div', 'st-strip-score');
    const [h, a] = match.score ?? [0, 0];
    const part = (value, side) => (highlight === side ? el('mark', null, String(value)) : el('span', null, String(value)));
    const dash = el('span', null, '–');
    dash.style.color = '#4a443b';
    dash.style.padding = '0 10px';
    score.append(part(h, 'home'), dash, part(a, 'away'));
    box.append(home, score, away);
    return box;
}

function picture(team, minute, photo, name) {
    const box = el('div', 'st-pic');
    box.style.background = team.colour;
    const inner = el('div', 'st-pic-inner');
    if (team.art) {
        const img = el('img');
        img.src = team.art;
        img.alt = team.army ? `${team.name}'s army, the ${team.army}` : team.name;
        inner.append(img);
    } else {
        inner.append(tile(el('span', 'st-tile'), team));
    }
    box.append(inner);
    if (photo) {
        // The player's photo (API-Football, about 150 px) in a round badge over their army.
        const face = el('img', 'st-face');
        face.src = photo;
        face.alt = name ?? '';
        face.addEventListener('error', () => face.remove());
        box.append(face);
    }
    if (minute) box.append(el('span', 'st-badge', minute));
    return box;
}

function renderMoment(m) {
    const node = $('moment');
    const match = (snapshot?.live ?? []).find((x) => x.id === m.match.id) ?? m.match;
    node.className = `st-moment ${m.type === 'goal' ? 'goal' : 'red'}`;
    node.dataset.id = m.id;
    if (m.type === 'goal') {
        const goal = (match.goals ?? []).find((g) => g.id === m.id) ?? m.goal;
        const team = sideTeam(match, goal.side);
        const extras = [team.name, goal.own_goal ? 'Own goal' : null, goal.penalty ? 'Penalty' : null].filter(Boolean).join(' · ');
        const griot = match.griot && match.griot.kind === 'goal' ? `“${match.griot.headline}”` : null;
        node.replaceChildren(
            el('div', 'st-moment-word', 'GOAL!'),
            picture(team, goal.minute, goal.photo, goal.scorer),
            el('div', 'st-who', goal.scorer ?? `${team.name} score!`),
            el('div', 'st-sub', extras),
            strip(match, goal.side),
            ...(griot ? [el('div', 'st-call', griot)] : []),
        );
    } else {
        const red = m.red;
        const team = sideTeam(match, red.side);
        const word = el('div', 'st-moment-word');
        word.append(el('span', 'st-redcard'), el('span', null, 'RED CARD'));
        node.replaceChildren(
            word,
            picture(team, red.minute, red.photo, red.player),
            el('div', 'st-who', red.player ?? 'Red card'),
            el('div', 'st-sub', `${team.name} down to ten`),
            strip(match, null),
        );
    }
    node.hidden = false;
}

function playMoments() {
    const t = Date.now();
    if (showing && showing.until > t) {
        // A scorer named after the goal went up is filled in while the moment is still on screen.
        if (showing.moment.type === 'goal') {
            const match = (snapshot?.live ?? []).find((x) => x.id === showing.moment.match.id);
            const goal = match?.goals?.find((g) => g.id === showing.moment.id);
            if (goal && (goal.scorer || goal.photo) && showing.named !== `${goal.scorer}|${goal.photo}`) {
                showing.named = `${goal.scorer}|${goal.photo}`;
                renderMoment(showing.moment);
            }
        }
        return;
    }
    if (showing) {
        $('moment').hidden = true;
        showing = null;
    }
    const m = queue.shift();
    if (!m) return;
    showing = { moment: m, until: t + MOMENT, named: m.type === 'goal' ? `${m.goal.scorer}|${m.goal.photo}` : null };
    pinned = { id: m.match.id, until: t + PIN };
    renderMoment(m);
    if (m.type === 'goal') sound.goal(); else sound.red();
}

let flashTimer = null;
function flash(m) {
    const node = $('flash');
    const team = sideTeam(m.match, m.goal.side);
    const [h, a] = m.match.score ?? [0, 0];
    node.replaceChildren(el('strong', null, 'GOAL RULED OUT'), el('span', null, `${team.name}'s goal does not stand. ${m.match.home.short} ${h}–${a} ${m.match.away.short}`));
    node.hidden = false;
    sound.red();
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { node.hidden = true; }, 5000);
}

/* ---------- each snapshot ---------- */
function apply(next) {
    const prev = snapshot;
    snapshot = next;
    for (const m of diff(prev, next)) {
        if (m.type === 'disallowed') {
            queue.splice(0, queue.length, ...queue.filter((q) => q.id !== m.id));
            flash(m);
        } else {
            queue.push(m);
        }
    }
    // The Griot's voice for a new goal or red-card post, once each (when the site has the clip).
    for (const match of next.live ?? []) {
        const g = match.griot;
        if (!g || voiced.has(g.id)) continue;
        voiced.add(g.id);
        if (prev && g.audio && ['goal', 'red_card'].includes(g.kind)) sound.voice(g.audio);
    }
    render();
}

function render() {
    if (!snapshot) return;
    const live = snapshot.live ?? [];
    const current = featured(live);
    $('live-pill').hidden = !live.length;
    $('updating').hidden = !(snapshot.stale || Date.now() - lastGood > POLL * 6);
    if (current) renderLive(current, live); else renderIdle(snapshot);
    renderTicker(snapshot, current);
}

async function poll() {
    try {
        let next;
        if (TEST) {
            next = fakeSnapshot(startedAt);
        } else {
            const response = await fetch(stage.dataset.feed, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error(`feed ${response.status}`);
            next = await response.json();
        }
        if (next.now) clockOffset = new Date(next.now).getTime() - Date.now();
        lastGood = Date.now();
        apply(next);
    } catch (e) {
        render(); // keep the last snapshot on screen; "Updating…" shows if this goes on
    } finally {
        setTimeout(poll, TEST ? 1000 : POLL);
    }
}

setInterval(() => {
    playMoments();
    if (snapshot && !(snapshot.live ?? []).length) renderCountdown();
    else if (snapshot) render();
}, 1000);
poll();
