import { readFile } from 'node:fs/promises';
import { encode, encoder, timedSpeech } from './audio.mjs';

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
    const paragraphs = props.paragraphs || [props.text];
    if (!Array.isArray(paragraphs) || !paragraphs.length || paragraphs.some(p => typeof p !== 'string' || !p.trim())
        || paragraphs.join('\n\n') !== props.text) throw new Error('Paragraphs do not match the speech script');
    const cues = [];
    if (props.voice === 'griot') {
        // The Griot: his voice over the talking-drum groove, a drum accent on each story (griot-sound.mjs).
        const { griotSpeaker, bulletin } = await import('./griot-sound.mjs');
        const speak = await griotSpeaker();
        const pieces = [new Float32Array(Math.round(24000 * 1.2))]; // the opening whistle and drum call play here
        for await (const samples of timedSpeech(paragraphs, speak, cues)) pieces.push(samples, new Float32Array(Math.round(24000 * 0.18)));
        const voice = new Float32Array(pieces.reduce((n, p) => n + p.length, 0));
        pieces.reduce((at, p) => (voice.set(p, at), at + p.length), 0);
        if (voice.length / 24000 > 305) throw new Error('Bulletin exceeds five minutes; shorten the script');
        const out = value('--out');
        const seconds = await bulletin(out, voice, cues.map(cue => cue.start));
        const { stat } = await import('node:fs/promises');
        console.log(JSON.stringify({ seconds, bytes: (await stat(out)).size, cues }));
        return;
    }
    const { env } = await import('@huggingface/transformers');
    const { KokoroTTS } = await import('kokoro-js');
    if (process.env.RADIO_TTS_CACHE) env.cacheDir = process.env.RADIO_TTS_CACHE;
    const tts = await KokoroTTS.from_pretrained('onnx-community/Kokoro-82M-v1.0-ONNX', {
        dtype: 'q8', device: 'cpu', session_options: { intraOpNumThreads: 2, interOpNumThreads: 1 },
    });
    const speech = timedSpeech(paragraphs, part => tts.generate(part, { voice: props.voice || 'bm_george', speed: 1 }), cues);
    const result = await encode(value('--out'), speech);
    console.log(JSON.stringify({ ...result, cues }));
}

main().catch(error => { console.error('Radio renderer: ' + error.message); process.exitCode = 1; });
