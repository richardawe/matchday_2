import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fit, lines, RATE, trim } from './speak.mjs';
import { addVoices } from '../reel/audio.mjs';

// A fake voice: 0.1 s of speech per character at speed 1, with 0.3 s of silence either side.
const synthesize = async (text, speed) => {
    const pad = new Float32Array(Math.round(RATE * 0.3));
    const voice = new Float32Array(Math.round((RATE * 0.1 * text.length) / speed)).fill(0.5);
    return Float32Array.from([...pad, ...voice, ...pad]);
};
const props = (beats) => ({ title: { say: ['A v B.'] }, beats, final: { say: ['Full time. A 1, B 0.', 'Full time.'] } });

test('one line per scene, with the full-time call starting over the numbers screen', () => {
    const goal = { type: 'goal', say: ['Saka scores!'] };
    const result = lines(props([goal, { type: 'goal' }]));
    assert.deepEqual(result.map((l) => l.say[0]), ['A v B.', 'Saka scores!', 'Full time. A 1, B 0.']);
    assert.ok(Math.abs(result[2].at - 16.1) < 1e-9);
    assert.ok(result[2].max > 3); // numbers screen plus the final card
    assert.ok(result[1].max < 7 - 0.2); // a beat leaves a breath before the next scene
});

test('the longest wording that fits wins, sped up if needed, silence trimmed', async () => {
    // 'Saka scores, 41 minutes!' is 2.4 s at speed 1, 2 s at 1.2: fits 2.1 s.
    const audio = await fit({ max: 2.1, say: ['Saka scores, 41 minutes!', 'Saka scores!'] }, synthesize);
    assert.ok(Math.abs(audio.length / RATE - (2.4 / 1.2 + 0.06)) < 0.01);
    // Too long even at the top speed: the next wording.
    const shorter = await fit({ max: 1.2, say: ['Saka scores, 41 minutes!', 'Saka scores!'] }, synthesize);
    assert.ok(Math.abs(shorter.length / RATE - (1.2 / 1.2 + 0.06)) < 0.01);
});

test('trim keeps 30 ms around the voice', () => {
    const samples = Float32Array.from([...new Float32Array(2400), 0.5, 0.5, ...new Float32Array(2400)]);
    assert.equal(trim(samples).length, 2 + 2 * Math.round(RATE * 0.03));
});

test('voices are mixed in at their start time over a dipped bed', () => {
    const mix = new Float32Array(44100).fill(0.1);
    addVoices(mix, [{ at: 0.5, samples: new Float32Array(2400).fill(0.25) }]);
    assert.ok(Math.abs(mix[100] - 0.1) < 1e-6); // before the line: untouched
    assert.ok(Math.abs(mix[22050 + 2205] - (0.04 + 1.6 * 0.25)) < 1e-3); // during: bed at 40% plus the voice
});
