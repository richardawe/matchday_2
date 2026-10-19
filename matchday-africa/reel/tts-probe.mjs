import { KokoroTTS } from 'kokoro-js';
const t0 = Date.now();
const tts = await KokoroTTS.from_pretrained('onnx-community/Kokoro-82M-v1.0-ONNX', { dtype: 'q8', device: 'cpu' });
console.log('load', (Date.now() - t0) / 1000, 's');
console.log('voices', Object.keys(tts.voices).join(' '));
const lines = [['am_fenrir', 'Knights, today we hold the line.'], ['bm_george', 'Na wa o. We dey find our shape.'], ['am_michael', 'Check the scoreboard, Knights. Two clean.']];
for (const [v, text] of lines) {
  const s = Date.now();
  const audio = await tts.generate(text, { voice: v });
  await audio.save(`${process.argv[2]}/${v}.wav`);
  console.log(v, ((Date.now() - s) / 1000).toFixed(2), 's gen,', (audio.audio.length / audio.sampling_rate).toFixed(2), 's audio');
}
