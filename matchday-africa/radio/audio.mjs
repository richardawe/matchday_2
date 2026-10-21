import { spawnSync } from 'node:child_process';
import { open, unlink, stat } from 'node:fs/promises';

export function chunks(text) {
    const words = text.trim().split(/\s+/);
    const result = [];
    let part = '';
    for (const word of words) {
        if (word.length > 200) throw new Error('Unpronounceable word in script');
        if ((part + ' ' + word).length > 450) { result.push(part); part = ''; }
        part += (part ? ' ' : '') + word;
        if (part.length > 200 && /[.!?]$/.test(word)) { result.push(part); part = ''; }
    }
    if (part) result.push(part);
    return result;
}

export function encoder(args) {
    const result = spawnSync(process.env.RADIO_FFMPEG || 'ffmpeg', args, { encoding: 'utf8', timeout: 120000, maxBuffer: 1024 * 1024 });
    if (result.error || result.status !== 0) throw new Error('FFmpeg failed: ' + (result.error?.message || result.stderr?.slice(-1000)));
    return result.stdout + result.stderr;
}

// Original three-note ident and a soft percussive tail. No publisher/broadcast recordings.
export function ident(rate = 24000) {
    const samples = new Float32Array(Math.round(rate * 1.2));
    for (let i = 0; i < samples.length; i++) {
        const t = i / rate, note = Math.min(2, Math.floor(t / 0.3)), local = t - note * 0.3;
        samples[i] = 0.14 * Math.sin(2 * Math.PI * [220, 277.18, 329.63][note] * t) * Math.exp(-7 * local)
            * Math.min(1, t * 80) * Math.min(1, (1.2 - t) * 12);
    }
    return samples;
}

export async function encode(out, pieces, rate = 24000) {
    const raw = out + '.pcm';
    const file = await open(raw, 'wx');
    let length = 0;
    const write = async samples => {
        length += samples.length;
        if (length / rate > 305) throw new Error('Bulletin exceeds five minutes; shorten the script');
        const bytes = Buffer.allocUnsafe(samples.length * 4);
        for (let i = 0; i < samples.length; i++) bytes.writeFloatLE(samples[i], i * 4);
        await file.writeFile(bytes);
    };
    try {
        await write(ident(rate));
        for await (const samples of pieces) {
            await write(samples);
            await write(new Float32Array(Math.round(rate * 0.18)));
        }
        await file.close();
        encoder(['-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-f', 'f32le', '-ar', String(rate), '-ac', '1', '-i', raw,
            '-af', 'loudnorm=I=-16:TP=-1.5:LRA=7', '-ar', '24000', '-ac', '1', '-c:a', 'libmp3lame', '-b:a', '24k', out]);
        // Decode the finished file before allowing publication.
        encoder(['-hide_banner', '-loglevel', 'error', '-nostdin', '-i', out, '-f', 'null', '-']);
        return { seconds: length / rate, bytes: (await stat(out)).size };
    } finally {
        await file.close().catch(() => {});
        await unlink(raw).catch(() => {});
    }
}

// Cue boundaries come from actual PCM samples, including the ident and chunk pauses.
export async function* timedSpeech(paragraphs, synthesize, cues, rate = 24000) {
    let cursor = 1.2;
    for (const [index, paragraph] of paragraphs.entries()) {
        const start = cursor;
        for (const part of chunks(paragraph.replace(/(\d)\s*[–-]\s*(\d)/g, '$1 $2'))) {
            const audio = await synthesize(part);
            if (audio.sampling_rate !== rate) throw new Error('Unexpected Kokoro sample rate');
            cursor += audio.audio.length / rate + 0.18;
            yield audio.audio;
        }
        cues.push({ paragraph: index, start, end: cursor });
    }
}
