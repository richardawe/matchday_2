// Renders one still from the middle of every scene, to check a reel's look without a full render.
//   node preview.mjs [--props match.json] [--out-dir previews] [--home roman-legion --away ottoman-janissary]
// Defaults to src/sample-v2.json with the Arsenal and Chelsea statues.
import { bundle } from '@remotion/bundler';
import { renderStill, selectComposition } from '@remotion/renderer';
import { mkdir, readFile, rm } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { stageReel } from './render.mjs';
import { timelineFor } from './src/timeline.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i > -1 ? process.argv[i + 1] : fallback; };
const props = JSON.parse(await readFile(arg('props', path.join(here, 'src/sample-v2.json')), 'utf8'));
for (const [side, fallback] of [['home', 'roman-legion'], ['away', 'ottoman-janissary']]) {
    if (!props[side].art_path && !props[side].art_url) props[side].art_path = path.join(here, '../public/war/army', `${arg(side, fallback)}-solid.webp`);
}
const outDir = arg('out-dir', path.join(here, 'previews'));
await mkdir(outDir, { recursive: true });
const [stage, inputProps] = await stageReel(props);
try {
    const serveUrl = await bundle({ entryPoint: path.join(here, 'src/index.jsx'), publicDir: stage });
    const browserExecutable = process.env.REEL_BROWSER || null;
    const comp = await selectComposition({ serveUrl, id: 'Reel', inputProps, browserExecutable });
    const scenes = timelineFor(inputProps);
    for (const [i, scene] of scenes.entries()) {
        const frame = scene.from + Math.min(scene.duration - 1, scene.kind === 'title' ? 20 : 45);
        const name = `${String(i).padStart(2, '0')}-${scene.kind}${scene.beat ? `-${scene.beat.type}` : ''}.jpg`;
        await renderStill({ composition: comp, serveUrl, inputProps, frame, output: path.join(outDir, name), browserExecutable, imageFormat: 'jpeg', jpegQuality: 80, scale: Number(arg('scale', 0.5)) });
        console.log(name);
    }
    console.log(`${comp.durationInFrames / comp.fps}s reel, ${scenes.length} scenes -> ${outDir}`);
} finally {
    await rm(stage, { recursive: true, force: true });
}
