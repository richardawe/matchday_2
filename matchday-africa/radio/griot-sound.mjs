// The Griot's sound: the voice (Kokoro, two stock styles blended), a talking-drum groove that plays
// under it and ducks while he speaks, and match sound effects (whistle, crowd roar, the "ooh" of a near
// miss, a drum roll for pressure). Everything is synthesised here, so there is no recording to license.
// Used by griot-voice.mjs (live timeline clips) and render.mjs (the daily radio bulletins).
import { readFile, writeFile, unlink } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const RATE = 24000;
export const BPM = 96;
const PITCH = 0.94;

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

/** Kokoro with a 'griot' voice: British English phonemes and the blended style. Returns (text) => {audio, sampling_rate}. */
export async function griotSpeaker() {
    const { env, Tensor, RawAudio } = await import('@huggingface/transformers');
    const { KokoroTTS } = await import('kokoro-js');
    if (process.env.RADIO_TTS_CACHE) env.cacheDir = process.env.RADIO_TTS_CACHE;
    const tts = await KokoroTTS.from_pretrained('onnx-community/Kokoro-82M-v1.0-ONNX', {
        dtype: 'q8', device: 'cpu', session_options: { intraOpNumThreads: 2, interOpNumThreads: 1 },
    });
    const style = await blendVoices(parseBlend(process.env.GRIOT_VOICE_BLEND || 'bm_george:0.6,am_onyx:0.4'));
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
    // Spoken 6% faster, then lowered a semitone (which slows it back down): the Griot's pitch at the set pace.
    return async (text) => {
        const audio = await tts.generate(text.replace(/(\d)\s*[–-]\s*(\d)/g, '$1 $2'), { voice: 'griot', speed: speed / PITCH });
        return { audio: lowerPitch(audio.audio, PITCH), sampling_rate: RATE };
    };
}

// ---------------------------------------------------------------- instruments

function noiseSource(seed = 7) {
    let s = seed;
    return () => ((s = (s * 16807) % 2147483647) / 2147483647) * 2 - 1;
}

function add(track, at, seconds, sample, rate = RATE) {
    const from = Math.round(at * rate);
    for (let i = 0; i < seconds * rate && from + i < track.length; i++) {
        if (from + i >= 0) track[from + i] += sample(i / rate, i);
    }
}

/** Talking drum: the tone falls as the player squeezes the cords. */
export function talkingDrum(track, at, { high = 210, low = 120, gain = 0.6, rate = RATE } = {}) {
    const noise = noiseSource(Math.round(at * 1000) + 3);
    let phase = 0;
    add(track, at, 0.55, (t) => {
        phase += (2 * Math.PI * (low + (high - low) * Math.exp(-t * 7))) / rate;
        return gain * (Math.sin(phase) * Math.exp(-t * 6) + 0.25 * noise() * Math.exp(-t * 60));
    }, rate);
}

/** Djembe bass: a deep, quick thump. */
function bass(track, at, gain, rate) {
    add(track, at, 0.35, (t) => gain * Math.sin(2 * Math.PI * (52 + 38 * Math.exp(-t * 18)) * t) * Math.exp(-t * 9), rate);
}

/** Shaker: a short hiss. */
function shaker(track, at, gain, rate, noise) {
    let last = 0;
    add(track, at, 0.06, (t) => {
        const n = noise();
        const hp = n - last; // crude high-pass keeps it bright
        last = n;
        return gain * hp * Math.exp(-t * 70);
    }, rate);
}

/** Iron bell (gankogui): two bright partials. */
function bell(track, at, gain, rate) {
    add(track, at, 0.18, (t) => gain * (Math.sin(2 * Math.PI * 880 * t) + 0.5 * Math.sin(2 * Math.PI * 1320 * t)) * Math.exp(-t * 22), rate);
}

/** Kora-like pluck (Karplus-Strong). */
function pluck(track, at, freq, gain, rate) {
    const period = Math.round(rate / freq);
    const noise = noiseSource(Math.round(freq * 13));
    const buf = Float32Array.from({ length: period }, () => noise() * 0.5);
    let idx = 0;
    add(track, at, 1.6, () => {
        const v = buf[idx];
        const next = buf[(idx + 1) % period];
        buf[idx] = 0.996 * 0.5 * (v + next);
        idx = (idx + 1) % period;
        return gain * v;
    }, rate);
}

/**
 * The groove: a 16-step West African pattern at 96 BPM (bell timeline, djembe bass, shaker, talking-drum
 * answers) with a soft kora phrase every two bars. Fades in and out.
 */
export function groove(seconds, { rate = RATE, gain = 1 } = {}) {
    const track = new Float32Array(Math.ceil(seconds * rate));
    const step = 60 / BPM / 4;
    const noise = noiseSource(19);
    const bellSteps = [0, 3, 6, 10, 12], bassSteps = [0, 6, 10], drumSteps = { 4: [230, 150], 12: [200, 130], 14: [250, 160] };
    const kora = [[293.66, 0], [349.23, 3], [392, 6], [440, 8], [392, 11], [349.23, 14]]; // D minor pentatonic phrase
    for (let bar = 0; bar * 16 * step < seconds; bar++) {
        const start = bar * 16 * step;
        for (let s = 0; s < 16; s++) {
            const at = start + s * step;
            if (bellSteps.includes(s)) bell(track, at, 0.05 * gain, rate);
            if (bassSteps.includes(s)) bass(track, at, 0.5 * gain, rate);
            if (s % 2 === 0) shaker(track, at, (s % 4 === 0 ? 0.07 : 0.045) * gain, rate, noise);
            if (drumSteps[s]) talkingDrum(track, at, { high: drumSteps[s][0], low: drumSteps[s][1], gain: 0.22 * gain, rate });
        }
        if (bar % 2 === 0) for (const [freq, s] of kora) pluck(track, start + s * step, freq, 0.16 * gain, rate);
    }
    const fade = Math.round(0.8 * rate);
    for (let i = 0; i < track.length; i++) track[i] *= Math.min(1, i / fade, (track.length - i) / fade);
    return track;
}

// ---------------------------------------------------------------- effects

/** Referee's whistle: a trilled pea whistle; blasts = [[start, seconds], ...]. */
export function whistle(track, at, blasts = [[0, 0.5]], rate = RATE) {
    for (const [offset, seconds] of blasts) {
        let phase = 0;
        add(track, at + offset, seconds, (t) => {
            phase += (2 * Math.PI * (2900 + 120 * Math.sin(2 * Math.PI * 38 * t))) / rate;
            const env = Math.min(1, t / 0.02) * Math.min(1, (seconds - t) / 0.05);
            return 0.22 * env * Math.sin(phase) * (0.7 + 0.3 * Math.sin(2 * Math.PI * 38 * t));
        }, rate);
    }
}

/** Crowd: low-passed noise under an envelope. shape 'roar' swells and holds; 'ooh' rises and falls; 'murmur' is soft. */
export function crowd(track, at, seconds, shape = 'roar', rate = RATE) {
    const noise = noiseSource(Math.round(at * 100) + 41);
    let low = 0, band = 0;
    add(track, at, seconds, (t) => {
        low += 0.06 * (noise() - low);
        band += 0.3 * (low - band);
        const x = t / seconds;
        const env = shape === 'ooh' ? Math.sin(Math.PI * Math.min(1, x)) ** 1.5
            : shape === 'murmur' ? 0.35 * Math.min(1, t / 0.4) * Math.min(1, (seconds - t) / 0.6)
                : Math.min(1, t / 0.25) * Math.min(1, (seconds - t) / 1.2);
        return (shape === 'ooh' ? 3.2 * (low - band) : 2.4 * low) * env;
    }, rate);
}

export function thud(track, at, rate = RATE) {
    add(track, at, 0.9, (t) => 0.9 * Math.sin(2 * Math.PI * (55 + 40 * Math.exp(-t * 12)) * t) * Math.exp(-t * 4), rate);
}

export function drumRoll(track, at, seconds, rate = RATE) {
    for (let t = 0, gap = 0.16; t < seconds; t += gap, gap = Math.max(0.05, gap * 0.9)) {
        talkingDrum(track, at + t, { high: 200 + 60 * (t / seconds), low: 120, gain: 0.25 + 0.4 * (t / seconds), rate });
    }
}

/** The call that opens a timeline clip, and how long the voice waits for it. */
export function openingCall(track, kind, rate = RATE) {
    const calls = {
        goal: () => { talkingDrum(track, 0, { high: 230, low: 120, gain: 0.8, rate }); talkingDrum(track, 0.2, { high: 260, low: 140, gain: 0.6, rate }); talkingDrum(track, 0.42, { high: 200, low: 110, gain: 0.7, rate }); return 0.9; },
        red_card: () => { thud(track, 0, rate); return 0.6; },
        var: () => { thud(track, 0, rate); return 0.6; },
        kickoff: () => { talkingDrum(track, 0, { high: 180, low: 110, gain: 0.6, rate }); talkingDrum(track, 0.3, { high: 240, low: 140, gain: 0.5, rate }); return 0.8; },
        half_time: () => { talkingDrum(track, 0, { high: 200, low: 120, gain: 0.5, rate }); thud(track, 0.35, rate); return 1.0; },
        full_time: () => { drumRoll(track, 0, 1.0, rate); thud(track, 1.05, rate); return 1.6; },
        pressure: () => { drumRoll(track, 0, 0.9, rate); return 1.0; },
    };
    return (calls[kind] ?? (() => { talkingDrum(track, 0, { high: 190, low: 120, gain: 0.45, rate }); return 0.55; }))();
}

/** Effects under the words. The owner asked for beats only (no crowd), so nothing plays under the voice but the groove. */
export function underneath() {}

// ---------------------------------------------------------------- mixing

function ffmpeg(args) {
    const result = spawnSync(process.env.GRIOT_FFMPEG || process.env.RADIO_FFMPEG || 'ffmpeg', args, { encoding: 'utf8', timeout: 300000, maxBuffer: 1024 * 1024 });
    if (result.error || result.status !== 0) throw new Error('FFmpeg failed: ' + (result.error?.message || result.stderr?.slice(-800)));
}

// All voice processing, ducking and mixing happens here in JavaScript. The server's FFmpeg is the small build
// that ships with the reel renderer (no equalizer, echo, compressor or sidechain filters), so FFmpeg is only
// asked for what that build has: loudness normalisation and the MP3 encoder.
export const FFMPEG_FILTERS = ['loudnorm'];

/** RBJ biquad: 'highpass' or 'peak' (gain in dB). Returns a new array. */
export function biquad(input, type, freq, { q = 0.707, gain = 0, rate = RATE } = {}) {
    const w = (2 * Math.PI * freq) / rate, cos = Math.cos(w), alpha = Math.sin(w) / (2 * q), A = 10 ** (gain / 40);
    let b0, b1, b2, a0, a1, a2;
    if (type === 'highpass') {
        b0 = (1 + cos) / 2; b1 = -(1 + cos); b2 = (1 + cos) / 2; a0 = 1 + alpha; a1 = -2 * cos; a2 = 1 - alpha;
    } else {
        b0 = 1 + alpha * A; b1 = -2 * cos; b2 = 1 - alpha * A; a0 = 1 + alpha / A; a1 = -2 * cos; a2 = 1 - alpha / A;
    }
    const out = new Float32Array(input.length);
    let x1 = 0, x2 = 0, y1 = 0, y2 = 0;
    for (let i = 0; i < input.length; i++) {
        const x = input[i];
        const y = (b0 * x + b1 * x1 + b2 * x2 - a1 * y1 - a2 * y2) / a0;
        x2 = x1; x1 = x; y2 = y1; y1 = y;
        out[i] = y;
    }
    return out;
}

/** Envelope follower (peak, attack/release in seconds). */
export function envelope(input, attack, release, rate = RATE) {
    const out = new Float32Array(input.length);
    const up = Math.exp(-1 / (attack * rate)), down = Math.exp(-1 / (release * rate));
    let level = 0;
    for (let i = 0; i < input.length; i++) {
        const x = Math.abs(input[i]);
        level = x > level ? up * level + (1 - up) * x : down * level + (1 - down) * x;
        out[i] = level;
    }
    return out;
}

/** The Griot's colour: warmth at 140 Hz, presence at 3.2 kHz, rumble cut, a small room, gentle compression. */
export function griotVoiceFx(voice, rate = RATE) {
    let v = biquad(voice, 'highpass', 70, { rate });
    v = biquad(v, 'peak', 140, { q: 1, gain: 3, rate });
    v = biquad(v, 'peak', 3200, { q: 1.2, gain: 2, rate });
    // Small room: two early reflections (as FFmpeg's aecho 0.85:0.55:38|74:0.16|0.09).
    const d1 = Math.round(0.038 * rate), d2 = Math.round(0.074 * rate);
    const room = new Float32Array(v.length);
    for (let i = 0; i < v.length; i++) room[i] = 0.85 * v[i] + 0.16 * (v[i - d1] ?? 0) + 0.09 * (v[i - d2] ?? 0);
    // Compressor: above the threshold, level rises at a third of the rate.
    const env = envelope(room, 0.005, 0.12, rate), threshold = 0.1, ratio = 3;
    for (let i = 0; i < room.length; i++) {
        if (env[i] > threshold) room[i] *= (threshold * (env[i] / threshold) ** (1 / ratio)) / env[i];
    }
    return room;
}

/** Duck the bed while the voice is speaking: down to a quarter (about 20 dB under the voice), so it never drowns him. */
export function duck(bed, voice, rate = RATE) {
    const env = envelope(voice, 0.015, 0.4, rate);
    const out = new Float32Array(bed.length);
    for (let i = 0; i < bed.length; i++) {
        const speaking = Math.min(1, (env[i] ?? 0) / 0.03);
        out[i] = bed[i] * (1 - 0.75 * speaking);
    }
    return out;
}

/**
 * Lower a voice by `factor` in pitch (0.94 is about a semitone), making it 1/factor longer. Ask Kokoro for
 * speech `factor` times faster first and the pace comes back to normal (as asetrate + atempo would).
 */
export function lowerPitch(samples, factor = 0.94) {
    const out = new Float32Array(Math.floor(samples.length / factor));
    for (let i = 0; i < out.length; i++) {
        const pos = i * factor, j = Math.floor(pos), t = pos - j;
        out[i] = (samples[j] ?? 0) * (1 - t) + (samples[j + 1] ?? 0) * t;
    }
    return out;
}

async function f32(file, samples) {
    await writeFile(file, Buffer.from(samples.buffer, samples.byteOffset, samples.byteLength));
}

/** Mix voice (already pitched), groove bed and effects into an MP3 and check it decodes. */
export async function mixToMp3(out, voice, bed, fx, bitrate = '40k') {
    const v = griotVoiceFx(voice);
    const b = duck(bed, v);
    const length = Math.max(v.length, b.length, fx.length);
    const mix = new Float32Array(length);
    for (let i = 0; i < length; i++) mix[i] = (v[i] ?? 0) + (b[i] ?? 0) + 0.9 * (fx[i] ?? 0);
    const file = out + '.mix.f32';
    try {
        await f32(file, mix);
        ffmpeg(['-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-f', 'f32le', '-ar', String(RATE), '-ac', '1', '-i', file,
            '-af', 'loudnorm=I=-16:TP=-1.5:LRA=9', '-ar', '24000', '-ac', '1', '-c:a', 'libmp3lame', '-b:a', bitrate, out]);
        ffmpeg(['-hide_banner', '-loglevel', 'error', '-nostdin', '-i', out, '-f', 'null', '-']);
    } finally {
        await unlink(file).catch(() => {});
    }
}

/** A timeline clip: opening call, the voice over the groove, effects for the kind of moment, a short tail. */
export async function clip(out, kind, voice, { bitrate = '40k' } = {}) {
    const lead = openingCall(new Float32Array(1), kind) + 0.05; // measure the call's length
    const seconds = lead + voice.length / RATE + 1.4;
    const length = Math.ceil(seconds * RATE);
    const voiceTrack = new Float32Array(length);
    voiceTrack.set(voice, Math.round(lead * RATE));
    const fx = new Float32Array(length);
    openingCall(fx, kind);
    underneath(fx, kind, lead, voice.length / RATE);
    await mixToMp3(out, voiceTrack, groove(seconds, { gain: 0.35 }), fx, bitrate);
    return seconds;
}

/**
 * A radio bulletin: the voice track (with its measured cue starts) over the groove, a talking-drum
 * accent as each story starts, a whistle and drum call for the opening, and a drum roll to close.
 */
export async function bulletin(out, voice, cueStarts, { bitrate = '32k', intro = 1.2 } = {}) {
    const seconds = voice.length / RATE + 2.5;
    const length = Math.ceil(seconds * RATE);
    const voiceTrack = new Float32Array(length);
    voiceTrack.set(voice);
    const fx = new Float32Array(length);
    talkingDrum(fx, 0.05, { high: 180, low: 110, gain: 0.6 });
    talkingDrum(fx, 0.45, { high: 240, low: 140, gain: 0.6 });
    for (const at of cueStarts.slice(1)) talkingDrum(fx, Math.max(intro, at - 0.25), { high: 220, low: 130, gain: 0.45 });
    drumRoll(fx, voice.length / RATE + 0.2, 1.2);
    thud(fx, voice.length / RATE + 1.45);
    await mixToMp3(out, voiceTrack, groove(seconds, { gain: 0.3 }), fx, bitrate);
    return seconds;
}
