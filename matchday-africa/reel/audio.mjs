// The reel's soundtrack, synthesised from scratch so there is no music licence to clear:
// a low horn on the title, a crowd bed that swells, a talking-drum hit (pitch bending down,
// like a squeezed dùndún) on every goal, a heavy thud on a red card, and a drum roll at the end.
import { writeFile } from 'node:fs/promises';
import { buildTimeline, FPS, TOTAL } from './src/timeline.js';

const RATE = 44100;

export async function writeSoundtrack(beats, file) {
    const length = Math.ceil((TOTAL / FPS) * RATE);
    const mix = new Float32Array(length);
    let seed = 7;
    const noise = () => ((seed = (seed * 16807) % 2147483647) / 2147483647) * 2 - 1;

    const add = (start, seconds, sample) => {
        const from = Math.floor(start * RATE);
        for (let i = 0; i < seconds * RATE && from + i < length; i++) mix[from + i] += sample(i / RATE);
    };
    // Talking drum: the tone falls as the player squeezes the drum's cords.
    const talkingDrum = (at, high = 210, low = 120, gain = 0.8) => {
        let phase = 0;
        add(at, 0.6, (t) => {
            phase += (2 * Math.PI * (low + (high - low) * Math.exp(-t * 7))) / RATE;
            return gain * Math.sin(phase) * Math.exp(-t * 6) + 0.25 * noise() * Math.exp(-t * 60);
        });
    };
    const thud = (at, gain = 0.9) => add(at, 0.9, (t) => gain * Math.sin(2 * Math.PI * (55 + 40 * Math.exp(-t * 12)) * t) * Math.exp(-t * 4));
    const horn = (at, seconds) => add(at, seconds, (t) => {
        const env = Math.min(1, t / 0.25) * Math.min(1, (seconds - t) / 0.4);
        const f = 110;
        return 0.18 * env * (Math.sin(2 * Math.PI * f * t) + 0.5 * Math.sin(2 * Math.PI * 2 * f * t) + 0.25 * Math.sin(2 * Math.PI * 3 * f * t));
    });

    // Crowd bed: filtered noise that breathes and rises towards full time.
    let low = 0;
    for (let i = 0; i < length; i++) {
        const t = i / RATE;
        low += 0.02 * (noise() - low);
        const swell = 0.05 + 0.05 * (t / (TOTAL / FPS)) + 0.015 * Math.sin(t * 1.7);
        mix[i] += low * swell * 6;
    }

    horn(0, 1.9);
    talkingDrum(0.05, 180, 110, 0.6);
    for (const scene of buildTimeline(beats)) {
        const at = scene.from / FPS;
        if (scene.kind === 'beat' && scene.beat.type === 'goal') {
            talkingDrum(at);
            talkingDrum(at + 0.22, 240, 140, 0.55);
            add(at, 1.6, (t) => noise() * 0.18 * Math.exp(-t * 1.6) * Math.min(1, t / 0.08)); // crowd roar
        } else if (scene.kind === 'beat' && scene.beat.type === 'red_card') {
            thud(at);
        } else if (scene.kind === 'beat') {
            talkingDrum(at, 170, 120, 0.45);
        } else if (scene.kind === 'final') {
            for (let k = 0; k < 10; k++) talkingDrum(at + k * 0.07, 200 + k * 6, 120, 0.25 + k * 0.04);
            thud(at + 0.75, 1);
        }
    }

    // Soft limiter, then 16-bit PCM WAV.
    const pcm = Buffer.alloc(44 + length * 2);
    pcm.write('RIFF', 0); pcm.writeUInt32LE(36 + length * 2, 4); pcm.write('WAVEfmt ', 8);
    pcm.writeUInt32LE(16, 16); pcm.writeUInt16LE(1, 20); pcm.writeUInt16LE(1, 22);
    pcm.writeUInt32LE(RATE, 24); pcm.writeUInt32LE(RATE * 2, 28); pcm.writeUInt16LE(2, 32); pcm.writeUInt16LE(16, 34);
    pcm.write('data', 36); pcm.writeUInt32LE(length * 2, 40);
    const fadeOut = RATE * 0.6;
    for (let i = 0; i < length; i++) {
        const tail = Math.min(1, (length - i) / fadeOut);
        pcm.writeInt16LE(Math.round(Math.tanh(mix[i] * 1.2) * tail * 32000), 44 + i * 2);
    }
    await writeFile(file, pcm);
}
