import test from 'node:test';
import assert from 'node:assert/strict';
import { parseBlend, blendVoices, drumCall, griotFilter } from './griot-voice.mjs';

test('blend weights are normalised and names checked', () => {
    assert.deepEqual(parseBlend('bm_george:3,am_onyx:1'), [{ name: 'bm_george', weight: 0.75 }, { name: 'am_onyx', weight: 0.25 }]);
    assert.throws(() => parseBlend('../etc:1'));
    assert.throws(() => parseBlend('bm_george:0'));
});

test('voices are mixed vector by vector', async () => {
    const files = { ab_x: new Float32Array([1, 2, 3, 4]), ab_y: new Float32Array([3, 2, 1, 0]) };
    const read = async (name) => Buffer.from(files[name].buffer);
    assert.deepEqual([...await blendVoices(parseBlend('ab_x:1,ab_y:1'), read)], [2, 2, 2, 2]);
});

test('a goal gets a longer drum call than a near miss, and a red card a thud', () => {
    const goal = drumCall('goal'), chance = drumCall('big_chance'), red = drumCall('red_card');
    assert.ok(goal.length > chance.length);
    assert.ok(Math.max(...red.map(Math.abs)) > 0.3);
    assert.ok(goal.every(Number.isFinite));
});

test('the voice waits for the drum', () => {
    assert.match(griotFilter(1.0), /adelay=1050:all=1/);
    assert.match(griotFilter(1.0), /asetrate=24000\*0.94/);
});
