// The Griot's voice for the live timeline: one short clip per post.
//   node griot-voice.mjs --jobs jobs.json            (jobs: [{id, kind, text, out}], out = .mp3 path)
//   node griot-voice.mjs --audition dir              (writes sample clips to dir)
// Each clip: an opening call for the kind of moment (whistle, talking drum, drum roll), then the Griot
// (Kokoro, two voices blended, lowered and warmed) over a talking-drum groove that ducks while he speaks,
// with crowd effects underneath (a roar for a goal, an "ooh" for a near miss). See griot-sound.mjs.
// Prints JSON [{id, out, seconds}] or exits non-zero. Each clip is checked by decoding it.
import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { trim } from './speak.mjs';
import { griotSpeaker, clip } from './griot-sound.mjs';

export async function render(speak, job) {
    if (!job.out?.endsWith('.mp3')) throw new Error('Each job needs an .mp3 out path');
    await mkdir(path.dirname(job.out), { recursive: true });
    const voice = trim((await speak(job.text)).audio);
    const seconds = await clip(job.out, job.kind, voice);
    return { id: job.id, out: job.out, seconds: Math.round(seconds * 10) / 10 };
}

const AUDITION = [
    { id: 'kickoff', kind: 'kickoff', text: 'Hear me, the armies meet! The Norse Vikings and the Byzantine Cataphracts take the field. Let the drums speak.' },
    { id: 'goal', kind: 'goal', text: 'The Norse Vikings press on! Alexander Isak makes it 2 0. Bradley Barcola laid it on for him.' },
    { id: 'woodwork', kind: 'woodwork', text: 'The post stands guard! Yasin Ayari strikes from distance and the frame shakes. Still 2 0.' },
    { id: 'pressure', kind: 'pressure', text: 'The Byzantine Cataphracts press forward! 4 shots in 1 minute, 1 on target. The Norse Vikings hold on.' },
    { id: 'half_time', kind: 'half_time', text: 'The drums pause! Half-time: Liverpool 2 0 Brighton. Shots 9 to 8.' },
    { id: 'full_time', kind: 'full_time', text: 'Let it be told: the Norse Vikings prevail! Full time: Liverpool 2 0 Brighton. Shots 13 to 17.' },
];

async function main() {
    const arg = (name) => { const i = process.argv.indexOf(`--${name}`); return i > -1 ? process.argv[i + 1] : null; };
    let jobs;
    if (arg('audition')) jobs = AUDITION.map((job) => ({ ...job, out: path.join(arg('audition'), `griot-${job.id}.mp3`) }));
    else if (arg('jobs')) jobs = JSON.parse(await readFile(arg('jobs'), 'utf8'));
    else throw new Error('Usage: node griot-voice.mjs --jobs jobs.json | --audition dir');
    if (!Array.isArray(jobs) || !jobs.length) { console.log('[]'); return; }
    const speak = await griotSpeaker();
    const done = [];
    for (const job of jobs) done.push(await render(speak, job));
    console.log(JSON.stringify(done));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    main().catch((error) => { console.error('Griot voice: ' + error.message); process.exitCode = 1; });
}
