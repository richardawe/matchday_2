/**
 * Test mode (/stream?test=1): a made-up match day played fast, in the same shape as
 * /stream/feed, so the screen can be checked before a real match: goals (one named late), a red
 * card, a goal ruled out, two live matches taking turns, then the between-matches screen.
 * One loop lasts 150 seconds; each loop uses new ids so its goals are announced again.
 */
const art = (name) => `/war/army/${name}-solid.webp`;
const mood = (name, m) => `/war/army/moods/${name}-${m}.webp`;
const team = (name, short, colour, ink, army, file) => ({
    name, short, colour, ink, army, art: art(file),
    moods: { warrior: mood(file, 'warrior'), victory: mood(file, 'victory'), dejected: mood(file, 'dejected'), defeated: mood(file, 'defeated') },
});
const LIV = team('Liverpool', 'LIV', '#C8102E', '#ffffff', 'Norse Vikings', 'norse-viking');
const MCI = team('Man City', 'MCI', '#6CABDD', '#0b0b0b', 'Mongol Horde', 'mongol-horde');
const ARS = team('Arsenal', 'ARS', '#EF0107', '#ffffff', 'Roman Legion', 'roman-legion');
const CHE = team('Chelsea', 'CHE', '#034694', '#ffffff', 'Ottoman Janissaries', 'ottoman-janissary');
const NEW = { name: 'Newcastle', short: 'NEW', colour: '#241F20', ink: '#ffffff' };
const TOT = { name: 'Tottenham', short: 'TOT', colour: '#FFFFFF', ink: '#0b0b0b' };
const LOOP = 150;
// A stand-in for a player photo, so test mode shows the round photo badge.
const FACE = `data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 150 150"><rect width="150" height="150" fill="#d9d4c7"/><circle cx="75" cy="58" r="30" fill="#4a443b"/><path d="M20 150c0-35 25-55 55-55s55 20 55 55z" fill="#4a443b"/></svg>')}`;

function goals(id, list, t) {
    const out = { home: [], away: [] };
    for (const g of list) {
        if (t < g.at || (g.until !== undefined && t >= g.until)) continue;
        out[g.side].push(g);
    }
    const rows = [];
    for (const side of ['home', 'away']) {
        out[side].forEach((g, i) => rows.push({
            id: `${id}:${side}:${i + 1}`, side, minute: g.minute,
            scorer: g.namedAt !== undefined && t < g.namedAt ? null : g.scorer, own_goal: false, penalty: false,
            photo: g.photo && !(g.namedAt !== undefined && t < g.namedAt) ? FACE : null,
        }));
    }
    return rows;
}

export function fakeSnapshot(startedAt) {
    const elapsed = (Date.now() - startedAt) / 1000;
    const loop = Math.floor(elapsed / LOOP);
    const t = elapsed % LOOP;
    const now = new Date();
    const at = (minutes) => new Date(now.getTime() + minutes * 60000).toISOString();
    const id1 = 9000 + loop * 10;
    const id2 = id1 + 1;

    if (t >= 120) {
        return {
            now: now.toISOString(), stale: false, live: [], test: true,
            next: { id: id1 + 5, status: 'TIMED', kickoff: at(42 - (t - 120) / 60), kickoff_lagos: '20:00', score: null, home: NEW, away: TOT },
            later: [{ id: id1 + 6, status: 'TIMED', kickoff: at(180), kickoff_lagos: '22:15', score: null, home: { ...ARS }, away: { ...LIV } }],
            results: [
                { id: id1, status: 'FINISHED', kickoff: at(-120), kickoff_lagos: '17:30', score: [3, 1], home: LIV, away: MCI },
                { id: id2, status: 'FINISHED', kickoff: at(-110), kickoff_lagos: '17:30', score: [1, 0], home: ARS, away: CHE },
            ],
            reel: null,
        };
    }

    const minute = 60 + Math.floor(t / 3);
    const g1 = goals(id1, [
        { side: 'home', at: -1, minute: "23′", scorer: 'Mohamed Salah' },
        { side: 'away', at: -1, minute: "41′", scorer: 'Erling Haaland' },
        { side: 'home', at: 12, minute: "64′", scorer: 'Cody Gakpo', namedAt: 18, photo: true },
        { side: 'home', at: 50, minute: "76′", scorer: 'Mohamed Salah', photo: true },
        { side: 'home', at: 75, until: 84, minute: "85′", scorer: 'Darwin Núñez' },
    ], t);
    const g2 = goals(id2, [{ side: 'home', at: 40, minute: "43′", scorer: 'Bukayo Saka' }], t);
    const reds = t >= 30 ? [{ id: `${id1}:red:1`, side: 'away', minute: "70′", player: 'Rodri', photo: FACE }] : [];
    // [from, kind, headline, line, player named]: the Griot's words as the site writes them now.
    const lines = [
        [0, 'pressure', 'Liverpool pile on the pressure', 'Liverpool come again and again: 6 shots in 5 minutes.', null],
        [12, 'goal', 'GOAL! Cody Gakpo puts Liverpool in front', 'Liverpool ahead in the 64th minute. Liverpool 2–1 Manchester City.', 'Cody Gakpo'],
        [30, 'red_card', 'RED CARD! Rodri is sent off', 'Man City are down to ten in the 70th minute. Still 2–1.', 'Rodri'],
        [50, 'goal', 'GOAL! Mohamed Salah makes it 3–1', 'Liverpool pull further ahead in the 76th minute. Liverpool 3–1 Manchester City.', 'Mohamed Salah'],
        [84, 'var', 'VAR: no goal for Man City', 'Erling Haaland thought he had scored in the 84th minute. The screen says no. Still 3–1.', 'Erling Haaland'],
    ].filter(([from]) => t >= from).pop();
    const count = (side, list) => list.filter((g) => g.side === side).length;
    const live = (id, home, away, rows, red, clock, griot, stats, call) => ({
        id, status: 'IN_PLAY', kickoff: at(-minute), kickoff_lagos: '17:30', competition: 'Premier League', venue: null,
        score: [count('home', rows), count('away', rows)], home, away, clock, goals: rows, reds: red, stats, griot, call,
    });

    return {
        now: now.toISOString(), stale: t >= 95 && t < 105, test: true,
        live: [
            live(id1, LIV, MCI, g1, reds, `${minute}′`,
                { id: id1 * 10 + lines[0], kind: lines[1], clock: `${minute}′`, headline: lines[2], line: lines[3], audio: null,
                    photo: lines[4] ? FACE : null, player: lines[4] },
                { possession: [58, 42], shots: [14 + Math.floor(t / 20), 7] }, [2, 1]),
            live(id2, ARS, CHE, g2, [], `${minute - 30}′`, null, { possession: [51, 49], shots: [6, 5] }, null),
        ],
        next: { id: id1 + 5, status: 'TIMED', kickoff: at(150), kickoff_lagos: '20:00', score: null, home: NEW, away: TOT },
        later: [],
        results: [],
        reel: null,
    };
}
