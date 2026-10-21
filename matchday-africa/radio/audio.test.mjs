import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chunks, encode, timedSpeech } from './audio.mjs';

test('story cues follow the real audio samples and chunk pauses', async () => {
    const cues = [];
    const paragraphs = ['Welcome to Matchday Africa.', 'Football news follows here.', 'That is your update.'];
    const synthesize = async () => ({ sampling_rate: 24000, audio: new Float32Array(48000) });
    const samples = [];
    for await (const piece of timedSpeech(paragraphs, synthesize, cues)) samples.push(piece);
    assert.equal(samples.length, 3);
    assert.equal(cues[0].start, 1.2);
    assert.ok(Math.abs(cues[1].start - 3.38) < 0.001);
    assert.ok(Math.abs(cues[1].end - 5.56) < 0.001);
    assert.equal(cues[1].end, cues[2].start);
    assert.equal(cues[1].paragraph, 1);
});

test('long scripts are split without losing or duplicating words', () => {
    const text = ('Welcome to Matchday Africa. Scores and fixtures follow. ').repeat(70).trim();
    const pieces = chunks(text);
    assert.ok(pieces.every(s => s.length <= 450));
    assert.equal(pieces.join(' '), text);
});

test('the ident and mono audio encode to a small, decodable MP3', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'matchday-radio-audio-'));
    try {
        const out = join(dir, 'test.mp3');
        async function* speech() { yield Float32Array.from({ length: 24000 * 8 }, (_, i) => Math.sin(2 * Math.PI * 220 * i / 24000) * 0.1); }
        const result = await encode(out, speech());
        assert.ok(result.seconds > 9 && result.seconds < 10);
        assert.ok(result.bytes > 20000 && result.bytes < 40000);
        const bytes = await readFile(out);
        assert.equal(bytes.subarray(0, 3).toString(), 'ID3');
    } finally { await rm(dir, { recursive: true, force: true }); }
});
