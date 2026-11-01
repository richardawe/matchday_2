import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { parseBlend, blendVoices, groove, openingCall, underneath, clip, bulletin, lowerPitch, duck, griotVoiceFx, RATE } from './griot-sound.mjs';
import { readFileSync } from 'node:fs';

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
    // Beats only: no crowd under the voice for any kind of moment.
    for (const kind of ['goal', 'big_chance', 'woodwork', 'full_time', 'red_card', 'pressure']) {
        const under = new Float32Array(RATE * 6);
        underneath(under, kind, 1, 3);
        assert.equal(peak(under), 0, kind);
    }
});

test('the groove ducks under the voice', () => {
    const bed = new Float32Array(RATE).fill(0.5);
    const voice = new Float32Array(RATE);
    voice.fill(0.3, RATE / 2);
    const ducked = duck(bed, voice);
    assert.ok(ducked[RATE / 4] > 0.45);
    assert.ok(ducked[RATE - 1] < 0.15);
});

test('the voice is lowered a semitone and coloured without blowing up', () => {
    const tone = Float32Array.from({ length: RATE }, (_, i) => Math.sin(2 * Math.PI * 200 * i / RATE) * 0.3);
    const low = lowerPitch(tone, 0.94);
    assert.equal(low.length, Math.floor(RATE / 0.94));
    // Zero crossings drop by 6%: 200 Hz becomes 188 Hz.
    const crossings = (a) => a.reduce((n, v, i) => n + (i && Math.sign(v) !== Math.sign(a[i - 1]) ? 1 : 0), 0) / (a.length / RATE);
    assert.ok(Math.abs(crossings(low) / 2 - 188) < 3);
    const fx = griotVoiceFx(tone);
    assert.ok(fx.every(Number.isFinite) && Math.max(...fx.map(Math.abs)) < 1);
});

test('only FFmpeg features the server build has are used', () => {
    // The server runs the reel renderer's small FFmpeg: no equalizer, aecho, acompressor or sidechain filters.
    const source = readFileSync(new URL('./griot-sound.mjs', import.meta.url), 'utf8');
    for (const filter of ['sidechaincompress', 'aecho', 'equalizer', 'acompressor', 'asplit', 'highpass=', 'asetrate']) {
        assert.ok(!source.includes(`'${filter}`) && !source.includes(`,${filter}`) && !source.includes(`[0:a]${filter}`), filter);
    }
    assert.match(source, /'-af', 'loudnorm=/);
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
