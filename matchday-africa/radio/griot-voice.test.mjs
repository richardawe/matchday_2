import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { parseBlend, blendVoices, groove, openingCall, underneath, griotGraph, clip, bulletin, RATE } from './griot-sound.mjs';

const peak = (track) => track.reduce((m, v) => Math.max(m, Math.abs(v)), 0);

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

test('the groove fills the time, fades at both ends and stays finite', () => {
    const bed = groove(6);
    assert.equal(bed.length, 6 * RATE);
    assert.ok(peak(bed) > 0.1 && bed.every(Number.isFinite));
    assert.ok(Math.abs(bed[0]) < 1e-9);
    assert.ok(Math.abs(bed.at(-1)) < 1e-3);
});

test('each kind of moment opens differently and the voice waits for it', () => {
    const lengths = Object.fromEntries(['goal', 'full_time', 'big_chance', 'red_card', 'kickoff'].map((k) => [k, openingCall(new Float32Array(RATE * 3), k)]));
    assert.ok(lengths.full_time > lengths.goal && lengths.goal > lengths.big_chance);
    const roar = new Float32Array(RATE * 6), none = new Float32Array(RATE * 6);
    underneath(roar, 'goal', 1, 3);
    underneath(none, 'injury', 1, 3);
    assert.ok(peak(roar) > 0.05);
    assert.equal(peak(none), 0);
});

test('the groove ducks under the voice', () => {
    assert.match(griotGraph(), /sidechaincompress/);
    assert.match(griotGraph(), /asetrate=24000\*0\.94/);
});

test('a clip and a bulletin encode to decodable MP3s with the music around the voice', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'griot-sound-'));
    try {
        const voice = Float32Array.from({ length: RATE * 3 }, (_, i) => Math.sin(2 * Math.PI * 200 * i / RATE) * 0.2);
        const seconds = await clip(join(dir, 'goal.mp3'), 'goal', voice);
        assert.ok(seconds > 5 && seconds < 6);
        assert.equal((await readFile(join(dir, 'goal.mp3'))).subarray(0, 3).toString(), 'ID3');
        const long = await bulletin(join(dir, 'b.mp3'), new Float32Array(RATE * 10).fill(0.01), [1.2, 4, 7]);
        assert.ok(long > 12 && long < 13);
        assert.equal((await readFile(join(dir, 'b.mp3'))).subarray(0, 3).toString(), 'ID3');
    } finally { await rm(dir, { recursive: true, force: true }); }
});
