// The reel's voice-over: Kokoro (82M parameters, Apache-2.0) reads each speech bubble aloud on
// the CPU, one voice per army. The model (~90 MB) downloads from Hugging Face on first use and is
// cached; set REEL_TTS_CACHE to keep it outside node_modules so reinstalls don't fetch it again.
// A line that would overrun its slot is spoken a little faster (up to 1.45×); one that still
// doesn't fit stays a silent bubble.
import { speakingRoom } from './src/timeline.js';

const MODEL = 'onnx-community/Kokoro-82M-v1.0-ONNX';
let tts = null;

async function load() {
    if (tts) return tts;
    const { env } = await import('@huggingface/transformers');
    if (process.env.REEL_TTS_CACHE) env.cacheDir = process.env.REEL_TTS_CACHE;
    const { KokoroTTS } = await import('kokoro-js');
    tts = await KokoroTTS.from_pretrained(MODEL, { dtype: 'q8', device: 'cpu' });
    return tts;
}

// What the voice should say: scores read as numbers ("two one"), not "two dash one".
export const spoken = (text) => text.replace(/(\d)\s*[–-]\s*(\d)/g, '$1 $2').replace(/…/g, '.');

// Returns the bubbles with `seconds` added where speech fits, and the speech itself (mono
// samples at Kokoro's rate) for audio.mjs to mix into the soundtrack.
export async function speakBubbles(beats, bubbles, voices, log = () => {}) {
    if (!bubbles?.length || !voices) return { bubbles: bubbles ?? [], speech: [] };
    const kokoro = await load();
    const room = speakingRoom(beats, bubbles);
    const said = [];
    const speech = [];
    for (const [i, bubble] of bubbles.entries()) {
        const voice = voices[bubble.side];
        let audio = await kokoro.generate(spoken(bubble.text), { voice });
        let seconds = audio.audio.length / audio.sampling_rate;
        // Kokoro's speed isn't exactly proportional, so aim a little short and try once more if needed.
        let speed = 1;
        for (const margin of [1.1, 1.25]) {
            if (seconds <= room[i] || room[i] <= 0 || speed >= 1.45) break;
            speed = Math.min(1.45, speed * (seconds / room[i]) * margin);
            audio = await kokoro.generate(spoken(bubble.text), { voice, speed });
            seconds = audio.audio.length / audio.sampling_rate;
        }
        if (seconds > room[i]) {
            log(`bubble ${i} stays silent: ${seconds.toFixed(1)}s of speech for ${room[i].toFixed(1)}s of room`);
            said.push(bubble);
            continue;
        }
        said.push({ ...bubble, seconds: Math.round(seconds * 100) / 100, voiced: i });
        speech[i] = { samples: audio.audio, rate: audio.sampling_rate };
    }
    return { bubbles: said, speech };
}
