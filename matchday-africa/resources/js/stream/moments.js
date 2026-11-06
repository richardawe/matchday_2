/**
 * Finds what changed between two stream snapshots (/stream/feed): new goals, goals ruled out,
 * new red cards. Ids come from App\Services\Stream\StreamFeed and stay the same between
 * snapshots, so each moment is announced once: `announced` remembers them for the session, and a
 * goal that is ruled out and later restored is not announced a second time (the same rule as the
 * match alerts). The first snapshot only fills the memory; nothing is announced for it.
 */
export function createMoments() {
    const announced = new Set();
    const ruledOut = new Set();
    let first = true;

    function remember(snapshot) {
        for (const match of snapshot?.live ?? []) {
            for (const goal of match.goals ?? []) announced.add(goal.id);
            for (const red of match.reds ?? []) announced.add(red.id);
        }
    }

    return function diff(prev, next) {
        if (first || !prev) {
            first = false;
            remember(next);
            return [];
        }
        const out = [];
        const before = new Map((prev.live ?? []).map((m) => [m.id, m]));
        for (const match of next.live ?? []) {
            const old = before.get(match.id);
            const goalIds = new Set((match.goals ?? []).map((g) => g.id));
            for (const goal of match.goals ?? []) {
                if (!announced.has(goal.id)) {
                    announced.add(goal.id);
                    // A match seen for the first time already carrying goals (the page opened
                    // mid-match, or the feed caught up) is remembered, not announced.
                    if (old) out.push({ type: 'goal', id: goal.id, match, goal });
                }
            }
            for (const goal of old?.goals ?? []) {
                if (!goalIds.has(goal.id) && !ruledOut.has(goal.id)) {
                    ruledOut.add(goal.id);
                    out.push({ type: 'disallowed', id: goal.id, match, goal });
                }
            }
            for (const red of match.reds ?? []) {
                if (!announced.has(red.id)) {
                    announced.add(red.id);
                    if (old) out.push({ type: 'red', id: red.id, match, red });
                }
            }
        }
        return out;
    };
}
