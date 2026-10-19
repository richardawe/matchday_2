// Renders the Griot's full-time reel to MP4.
//   node render.mjs --props match.json --out reel.mp4 [--composition Reel]
//   node render.mjs --props-url https://… --out reel.mp4   (GitHub Actions: a signed URL from the site)
// The props file is written by `php artisan reel:render`. Each army's `art_path` points at its
// crest-free cut-out; fonts, art and the generated soundtrack are staged in a temporary public
// folder so the render never needs the network.
import { bundle } from '@remotion/bundler';
import { renderMedia, selectComposition } from '@remotion/renderer';
import { copyFile, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { writeSoundtrack } from './audio.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));

export async function renderReel(props, out, { composition = 'Reel', log = console.log } = {}) {
    const started = Date.now();
    const stage = await mkdtemp(path.join(os.tmpdir(), 'matchday-reel-'));
    try {
        await mkdir(path.join(stage, 'fonts'));
        for (const font of ['Anton-Regular.ttf', 'IBMPlexSans.ttf', 'IBMPlexMono-SemiBold.ttf']) {
            await copyFile(path.join(here, '../resources/fonts', font), path.join(stage, 'fonts', font));
        }
        const inputProps = structuredClone(props);
        for (const side of ['home', 'away']) {
            const { art_path: file, art_url: url } = inputProps[side];
            inputProps[side].art = file || url ? `${side}.webp` : null;
            if (file) await copyFile(file, path.join(stage, `${side}.webp`));
            else if (url) await writeFile(path.join(stage, `${side}.webp`), Buffer.from(await (await fetch(url)).arrayBuffer()));
            delete inputProps[side].art_path;
            delete inputProps[side].art_url;
        }
        await writeSoundtrack(inputProps.beats, path.join(stage, 'soundtrack.wav'));
        inputProps.audio = 'soundtrack.wav';

        const serveUrl = await bundle({ entryPoint: path.join(here, 'src/index.jsx'), publicDir: stage });
        // REEL_BROWSER points at an existing Chrome/Chromium if the host can't use Remotion's download.
        const browserExecutable = process.env.REEL_BROWSER || null;
        const comp = await selectComposition({ serveUrl, id: composition, inputProps, browserExecutable });
        let last = -1;
        await renderMedia({
            composition: comp, serveUrl, inputProps, outputLocation: out, browserExecutable,
            codec: 'h264', crf: 28, audioCodec: 'aac', // about 3–4 MB: small enough to share on WhatsApp
            concurrency: process.env.REEL_CONCURRENCY ? Number(process.env.REEL_CONCURRENCY) : null,
            onProgress: ({ progress }) => {
                const pct = Math.floor(progress * 10) * 10;
                if (pct !== last) { last = pct; log(`rendering ${pct}%`); }
            },
        });
        return { seconds: Math.round((Date.now() - started) / 100) / 10, frames: comp.durationInFrames };
    } finally {
        await rm(stage, { recursive: true, force: true });
    }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const arg = (name) => { const i = process.argv.indexOf(`--${name}`); return i > -1 ? process.argv[i + 1] : null; };
    if (!(arg('props') || arg('props-url')) || !arg('out')) {
        console.error('Usage: node render.mjs --props match.json --out reel.mp4');
        process.exit(2);
    }
    const props = arg('props-url')
        ? await (await fetch(arg('props-url'), { headers: { Accept: 'application/json' } })).json()
        : JSON.parse(await readFile(arg('props'), 'utf8'));
    const result = await renderReel(props, arg('out'), { composition: arg('composition') ?? 'Reel' });
    console.log(`done in ${result.seconds}s (${result.frames} frames) -> ${arg('out')}`);
}
