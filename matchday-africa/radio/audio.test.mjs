import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chunks, encode } from './audio.mjs';

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
