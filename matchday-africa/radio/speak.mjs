// Speaks a reel's commentary with Kokoro: one short line per scene, fitted to the scene's length.
//   node speak.mjs --props reel.json --out-dir dir
// Each title, beat and final in the props may carry `say`: wordings from longest to shortest. The
// longest that fits its scene is used, sped up a little if needed. Writes one raw float32 mono
// 24 kHz file per line and prints JSON [{at, file, seconds}] for reel/render.mjs to mix in.
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildTimeline, FPS } from '../reel/src/timeline.js';

export const RATE = 24000;
const SPEED = 1.2; // a commentator's pace
const MAX_SPEED = 1.4;
const GAP = 0.2; // breath before the next scene

// The lines to speak: [{at, max, say}] in scene order. The numbers screen has no line of its own,
// so the full-time call starts there and runs into the final card (2 s alone is too short for it).
export function lines(props) {
    const say = (value) => (Array.isArray(value) ? value.filter((t) => typeof t === 'string' && t.trim()) : []);
    const scenes = buildTimeline(props.beats ?? []);
    const final = scenes.at(-1), numbers = scenes.at(-2);
    return scenes.flatMap((scene) => {
        if (scene.kind === 'final') return [];
        if (scene === numbers) {
            return [{ at: scene.from / FPS + 0.1, max: (scene.duration + final.duration) / FPS - 0.6, say: say(props.final?.say) }];
        }
        const lead = scene.kind === 'title' ? 0.15 : 0.1;
        return [{ at: scene.from / FPS + lead, max: scene.duration / FPS - GAP - lead, say: say(scene.kind === 'title' ? props.title?.say : scene.beat?.say) }];
    }).filter((line) => line.say.length);
}

// Kokoro pads each line with silence; keep 30 ms either side of the voice.
export function trim(samples, threshold = 0.01) {
    let start = 0, end = samples.length - 1;
    while (start < end && Math.abs(samples[start]) < threshold) start++;
    while (end > start && Math.abs(samples[end]) < threshold) end--;
    const pad = Math.round(RATE * 0.03);
    return samples.subarray(Math.max(0, start - pad), Math.min(samples.length, end + pad + 1));
}

// The longest wording that fits `max` seconds, at up to MAX_SPEED; otherwise the shortest, fastest.
export async function fit(line, synthesize) {
    let text;
    for (text of line.say) {
        const audio = trim(await synthesize(text, SPEED));
        const seconds = audio.length / RATE;
        if (seconds <= line.max) return audio;
        const speed = SPEED * (seconds / line.max);
        if (speed <= MAX_SPEED) return trim(await synthesize(text, speed));
    }
    return trim(await synthesize(text, MAX_SPEED));
}

async function main() {
    const arg = (name) => { const i = process.argv.indexOf(`--${name}`); return i > -1 ? process.argv[i + 1] : null; };
    if (!arg('props') || !arg('out-dir')) throw new Error('Usage: node speak.mjs --props reel.json --out-dir dir');
    const props = JSON.parse(await readFile(arg('props'), 'utf8'));
    const todo = lines(props);
    if (!todo.length) { console.log('[]'); return; }
    const { env } = await import('@huggingface/transformers');
    const { KokoroTTS } = await import('kokoro-js');
    if (process.env.RADIO_TTS_CACHE) env.cacheDir = process.env.RADIO_TTS_CACHE;
    const tts = await KokoroTTS.from_pretrained('onnx-community/Kokoro-82M-v1.0-ONNX', {
        dtype: 'q8', device: 'cpu', session_options: { intraOpNumThreads: 2, interOpNumThreads: 1 },
    });
    const voice = process.env.REEL_NARRATION_VOICE || 'bm_george';
    const synthesize = async (text, speed) => {
        const audio = await tts.generate(text.replace(/(\d)\s*[–-]\s*(\d)/g, '$1 $2'), { voice, speed });
        if (audio.sampling_rate !== RATE) throw new Error('Unexpected Kokoro sample rate');
        return audio.audio;
    };
    await mkdir(arg('out-dir'), { recursive: true });
    const out = [];
    for (const [i, line] of todo.entries()) {
        const samples = await fit(line, synthesize);
        const file = path.join(arg('out-dir'), `line-${i}.f32`);
        await writeFile(file, Buffer.from(samples.buffer, samples.byteOffset, samples.byteLength));
        out.push({ at: line.at, file, seconds: Math.round((samples.length / RATE) * 100) / 100 });
    }
    console.log(JSON.stringify(out));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    main().catch((error) => { console.error('Reel commentary: ' + error.message); process.exitCode = 1; });
}
