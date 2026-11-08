// Checks the v2 running order: always 20 seconds, moments in match order, none too short to call.
//   node test-timeline.mjs   (exits 1 on a failure; run by tests/Feature/ReelTimelineTest.php)
import assert from 'node:assert/strict';
import { FPS, timelineFor, totalFrames } from './src/timeline.js';

const beat = (type, i) => ({ type, side: i % 2 ? 'away' : 'home', home_score: 0, away_score: 0, n: i });
const cases = {
    'no moments': [],
    'one goal': [beat('goal', 0)],
    'kick-off, goal, half-time': ['kickoff', 'goal', 'half_time'].map(beat),
    'eight goals': Array.from({ length: 8 }, (_, i) => beat('goal', i)),
    'busy match': ['kickoff', 'pressure', 'big_chance', 'goal', 'woodwork', 'half_time', 'goal', 'red_card', 'injury', 'var', 'goal', 'big_chance'].map(beat),
    'fourteen goals': Array.from({ length: 14 }, (_, i) => beat('goal', i)),
};
for (const [name, beats] of Object.entries(cases)) {
    const props = { style: 'v2', beats };
    const scenes = timelineFor(props);
    assert.equal(totalFrames(props), 20 * FPS, `${name}: 20 seconds`);
    scenes.forEach((scene, i) => i && assert.equal(scene.from, scenes[i - 1].from + scenes[i - 1].duration, `${name}: scenes follow on`));
    const moments = scenes.filter((s) => s.kind === 'beat');
    moments.forEach((s) => assert.ok(s.duration >= 48, `${name}: ${s.beat.type} lasts at least 1.6 s`));
    const order = moments.map((s) => s.beat.n);
    assert.deepEqual(order, [...order].sort((a, b) => a - b), `${name}: match order`);
    if (name === 'busy match') {
        assert.ok(moments.filter((s) => s.beat.type === 'goal').length === 3, 'busy match keeps every goal');
        assert.ok(moments.some((s) => s.beat.type === 'red_card'), 'busy match keeps the red card');
    }
    if (name === 'fourteen goals') assert.equal(moments.at(-1).beat.n, 13, 'a goal-fest keeps the last goals');
}
console.log('timeline ok');
