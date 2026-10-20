import { readFile } from 'node:fs/promises';
import { chunks, encode, encoder } from './audio.mjs';

const args = process.argv.slice(2);
const value = key => args[args.indexOf(key) + 1];
async function main() {
    const [major, minor] = process.versions.node.split('.').map(Number);
    if (major < 22 || (major === 22 && minor < 13)) throw new Error('Radio needs Node 22.13 or later');
    if (!encoder(['-hide_banner', '-encoders']).includes('libmp3lame')) throw new Error('FFmpeg needs the libmp3lame encoder');
    // Resolve packages without starting the speech engine or its global exception handlers.
    import.meta.resolve('kokoro-js');
    import.meta.resolve('@huggingface/transformers');
    if (args.includes('--check')) {
        console.log('PASS: Node ' + process.version + ', Kokoro installed, FFmpeg MP3 encoder available. Use radio:check --sample to test model inference.');
        return;
    }
    if (!args.includes('--out')) throw new Error('--out is required');
    const props = args.includes('--sample') ? {
        voice: process.env.RADIO_VOICE || 'bm_george',
        text: 'Welcome to Matchday Africa. Your football, your next four minutes. This is a voice audition for our morning, midday and evening bulletins. Find the full transcript and sources on Matchday Africa.',
    } : JSON.parse(await readFile(value('--props'), 'utf8'));
    if (typeof props.text !== 'string' || props.text.length > 14000 || !props.text.trim()) throw new Error('Invalid speech script');
    const { env } = await import('@huggingface/transformers');
    const { KokoroTTS } = await import('kokoro-js');
    if (process.env.RADIO_TTS_CACHE) env.cacheDir = process.env.RADIO_TTS_CACHE;
    const tts = await KokoroTTS.from_pretrained('onnx-community/Kokoro-82M-v1.0-ONNX', {
        dtype: 'q8', device: 'cpu', session_options: { intraOpNumThreads: 2, interOpNumThreads: 1 },
    });
    const text = props.text.replace(/(\d)\s*[–-]\s*(\d)/g, '$1 $2');
    async function* speech() {
        for (const part of chunks(text)) {
            const audio = await tts.generate(part, { voice: props.voice || 'bm_george', speed: 1 });
            if (audio.sampling_rate !== 24000) throw new Error('Unexpected Kokoro sample rate');
            yield audio.audio;
        }
    }
    console.log(JSON.stringify(await encode(value('--out'), speech())));
}

main().catch(error => { console.error('Radio renderer: ' + error.message); process.exitCode = 1; });
