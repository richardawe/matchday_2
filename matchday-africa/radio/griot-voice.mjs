// The Griot's voice for the live timeline: Kokoro, made to sound like a storyteller at the drum.
//   node griot-voice.mjs --jobs jobs.json            (jobs: [{id, kind, text, out}], out = .mp3 path)
//   node griot-voice.mjs --audition dir              (writes sample clips to dir)
// Three things turn a stock Kokoro voice into the Griot:
//   1. a new voice: two Kokoro voice styles blended (GRIOT_VOICE_BLEND, "bm_george:0.6,am_onyx:0.4");
//   2. delivery: a slower pace, the voice lowered about a semitone, warmth and a small room around it;
//   3. the drum: a talking-drum call before each line (two for a goal, a low thud for a red card or VAR),
//      synthesised here like the reel's, so there is no recording to license.
// Prints JSON [{id, out, seconds}] or exits non-zero. Each clip is checked by decoding it.
import { readFile, writeFile, unlink, mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { trim, RATE } from './speak.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));

export function parseBlend(text) {
    const parts = String(text || '').split(',').map((part) => part.trim()).filter(Boolean).map((part) => {
        const [name, weight] = part.split(':');
        if (!/^[a-z]{2}_[a-z]+$/.test(name || '')) throw new Error(`Bad Kokoro voice name in blend: ${part}`);
        return { name, weight: weight === undefined ? 1 : Number(weight) };
    });
    const total = parts.reduce((sum, part) => sum + part.weight, 0);
    if (!parts.length || !(total > 0) || parts.some((part) => !(part.weight >= 0))) throw new Error('GRIOT_VOICE_BLEND needs voice:weight pairs with positive weights');
    return parts.map((part) => ({ ...part, weight: part.weight / total }));
}

// A weighted mix of Kokoro voice styles (each voice file is 510 × 256 float32 style vectors).
export async function blendVoices(blend, read = (name) => readFile(path.join(HERE, 'node_modules/kokoro-js/voices', `${name}.bin`))) {
    let mixed = null;
    for (const { name, weight } of blend) {
        const bytes = await read(name);
        const style = new Float32Array(bytes.buffer, bytes.byteOffset, bytes.byteLength / 4);
        mixed ??= new Float32Array(style.length);
        if (style.length !== mixed.length) throw new Error(`Voice ${name} has an unexpected size`);
        for (let i = 0; i < style.length; i++) mixed[i] += weight * style[i];
    }
    return mixed;
}

// The drum call that opens a line. Same instrument as reel/audio.mjs.
export function drumCall(kind, rate = RATE) {
    let seed = 11;
    const noise = () => ((seed = (seed * 16807) % 2147483647) / 2147483647) * 2 - 1;
    const hits = {
        goal: [[0, 230, 120, 0.8], [0.2, 260, 140, 0.6], [0.42, 200, 110, 0.7]],
        red_card: [[0, 0, 0, 0]], var: [[0, 0, 0, 0]],
        full_time: [[0, 200, 120, 0.5], [0.16, 210, 120, 0.55], [0.32, 220, 120, 0.6], [0.5, 180, 100, 0.8]],
        kickoff: [[0, 180, 110, 0.6], [0.25, 240, 140, 0.5]],
    }[kind] ?? [[0, 190, 120, 0.45]];
    const length = Math.round(rate * (Math.max(...hits.map((h) => h[0])) + 0.7));
    const out = new Float32Array(length);
    for (const [at, high, low, gain] of hits) {
        const from = Math.round(at * rate);
        let phase = 0;
        for (let i = 0; from + i < length; i++) {
            const t = i / rate;
            if (high === 0) { // a heavy thud
                out[from + i] += 0.9 * Math.sin(2 * Math.PI * (55 + 40 * Math.exp(-t * 12)) * t) * Math.exp(-t * 4);
                continue;
            }
            phase += (2 * Math.PI * (low + (high - low) * Math.exp(-t * 7))) / rate;
            out[from + i] += gain * Math.sin(phase) * Math.exp(-t * 6) + 0.25 * gain * noise() * Math.exp(-t * 60);
        }
    }
    return out;
}

// FFmpeg: the voice lowered ~1 semitone with its pace kept, warmed, given a small room and evened out;
// then the drum call, a breath, and the voice, normalised and encoded as a small MP3.
export function griotFilter(drumSeconds) {
    const delay = Math.round((drumSeconds + 0.05) * 1000);
    return [
        `[0:a]asetrate=${RATE}*0.94,aresample=${RATE},atempo=1/0.94,`
            + 'equalizer=f=140:t=q:w=1:g=3,equalizer=f=3200:t=q:w=1.2:g=2,highpass=f=70,'
            + 'aecho=0.85:0.55:38|74:0.16|0.09,acompressor=threshold=0.1:ratio=3:attack=5:release=120,'
            + `adelay=${delay}:all=1[v]`,
        '[1:a]volume=0.8[d]',
        '[v][d]amix=inputs=2:duration=longest:normalize=0,loudnorm=I=-16:TP=-1.5:LRA=7[out]',
    ].join(';');
}

function ffmpeg(args) {
    const result = spawnSync(process.env.GRIOT_FFMPEG || process.env.RADIO_FFMPEG || 'ffmpeg', args, { encoding: 'utf8', timeout: 120000, maxBuffer: 1024 * 1024 });
    if (result.error || result.status !== 0) throw new Error('FFmpeg failed: ' + (result.error?.message || result.stderr?.slice(-800)));
}

async function pcm(file, samples) {
    await writeFile(file, Buffer.from(samples.buffer, samples.byteOffset, samples.byteLength));
}

export async function loadGriot() {
    const { env, Tensor, RawAudio } = await import('@huggingface/transformers');
    const { KokoroTTS } = await import('kokoro-js');
    if (process.env.RADIO_TTS_CACHE) env.cacheDir = process.env.RADIO_TTS_CACHE;
    const tts = await KokoroTTS.from_pretrained('onnx-community/Kokoro-82M-v1.0-ONNX', {
        dtype: 'q8', device: 'cpu', session_options: { intraOpNumThreads: 2, interOpNumThreads: 1 },
    });
    const blend = parseBlend(process.env.GRIOT_VOICE_BLEND || 'bm_george:0.6,am_onyx:0.4');
    const style = await blendVoices(blend);
    // British English phonemes ('b') with the blended style in place of a stock voice.
    tts._validate_voice = (voice) => (voice === 'griot' ? 'b' : KokoroTTS.prototype._validate_voice.call(tts, voice));
    tts.generate_from_ids = async (ids, { speed = 1 } = {}) => {
        const offset = 256 * Math.min(Math.max(ids.dims.at(-1) - 2, 0), 509);
        const { waveform } = await tts.model({
            input_ids: ids,
            style: new Tensor('float32', style.slice(offset, offset + 256), [1, 256]),
            speed: new Tensor('float32', [speed], [1]),
        });
        return new RawAudio(waveform.data, RATE);
    };
    const speed = Number(process.env.GRIOT_VOICE_SPEED || 0.92);
    return async (text) => {
        const audio = await tts.generate(text.replace(/(\d)\s*[–-]\s*(\d)/g, '$1 $2'), { voice: 'griot', speed });
        return trim(audio.audio);
    };
}

export async function render(speak, job) {
    if (!job.out?.endsWith('.mp3')) throw new Error('Each job needs an .mp3 out path');
    await mkdir(path.dirname(job.out), { recursive: true });
    const voice = await speak(job.text);
    const drum = drumCall(job.kind);
    const voiceFile = job.out + '.voice.f32', drumFile = job.out + '.drum.f32';
    try {
        await pcm(voiceFile, voice);
        await pcm(drumFile, drum);
        ffmpeg(['-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
            '-f', 'f32le', '-ar', String(RATE), '-ac', '1', '-i', voiceFile,
            '-f', 'f32le', '-ar', String(RATE), '-ac', '1', '-i', drumFile,
            '-filter_complex', griotFilter(drum.length / RATE), '-map', '[out]',
            '-ar', '24000', '-ac', '1', '-c:a', 'libmp3lame', '-b:a', '40k', job.out]);
        ffmpeg(['-hide_banner', '-loglevel', 'error', '-nostdin', '-i', job.out, '-f', 'null', '-']);
    } finally {
        await unlink(voiceFile).catch(() => {});
        await unlink(drumFile).catch(() => {});
    }
    return { id: job.id, out: job.out, seconds: Math.round(((voice.length + drum.length) / RATE) * 10) / 10 };
}

const AUDITION = [
    { id: 'kickoff', kind: 'kickoff', text: 'Hear me, the armies meet! The Norse Vikings and the Byzantine Cataphracts take the field. Let the drums speak.' },
    { id: 'goal', kind: 'goal', text: 'The Norse Vikings press on! Alexander Isak makes it 2 0. Bradley Barcola laid it on for him.' },
    { id: 'woodwork', kind: 'woodwork', text: 'The post stands guard! Yasin Ayari strikes from distance and the frame shakes. Still 2 0.' },
    { id: 'pressure', kind: 'pressure', text: 'The Byzantine Cataphracts press forward! 4 shots in 2 minutes, 1 on target. The Norse Vikings hold on.' },
    { id: 'full_time', kind: 'full_time', text: 'Let it be told: the Norse Vikings prevail! Full time: Liverpool 2 0 Brighton. Shots 13 to 17.' },
];

async function main() {
    const arg = (name) => { const i = process.argv.indexOf(`--${name}`); return i > -1 ? process.argv[i + 1] : null; };
    let jobs;
    if (arg('audition')) jobs = AUDITION.map((job) => ({ ...job, out: path.join(arg('audition'), `griot-${job.id}.mp3`) }));
    else if (arg('jobs')) jobs = JSON.parse(await readFile(arg('jobs'), 'utf8'));
    else throw new Error('Usage: node griot-voice.mjs --jobs jobs.json | --audition dir');
    if (!Array.isArray(jobs) || !jobs.length) { console.log('[]'); return; }
    const speak = await loadGriot();
    const done = [];
    for (const job of jobs) done.push(await render(speak, job));
    console.log(JSON.stringify(done));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    main().catch((error) => { console.error('Griot voice: ' + error.message); process.exitCode = 1; });
}
