// Builds web-sized copies of the crest-free army statues (config/armies.php):
// public/war/downloads/rights-safe/<art>.png -> public/war/army/<art>-<width>.webp
// The 1.9 MB originals stay as the source for server-rendered story cards.
// Usage: node scripts/build-armies.mjs
import { mkdir, readdir, stat } from 'node:fs/promises';
import path from 'node:path';
import sharp from 'sharp';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const source = path.join(root, 'public/war/downloads/rights-safe');
const target = path.join(root, 'public/war/army');
const widths = [480, 960];

// Cut-outs: the statues sit on near-black, so alpha comes from brightness. Bright armour stays
// solid, the black backdrop becomes transparent, shadows go part-way. Used under coloured
// light on story cards (resources/armies, server only) and in the arena (public webp).
const cutoutDir = path.join(root, 'resources/armies');

async function cutout(file, width) {
    const { data, info } = await sharp(path.join(source, file)).resize({ width }).removeAlpha().raw().toBuffer({ resolveWithObject: true });
    const rgba = Buffer.alloc(info.width * info.height * 4);
    for (let i = 0, j = 0; i < data.length; i += 3, j += 4) {
        const peak = Math.max(data[i], data[i + 1], data[i + 2]);
        rgba[j] = data[i]; rgba[j + 1] = data[i + 1]; rgba[j + 2] = data[i + 2];
        rgba[j + 3] = Math.max(0, Math.min(255, (peak - 12) * 5));
    }
    return sharp(rgba, { raw: { width: info.width, height: info.height, channels: 4 } });
}

await mkdir(target, { recursive: true });
await mkdir(cutoutDir, { recursive: true });
const report = async (out) => console.log(`${path.relative(root, out)}  ${Math.round((await stat(out)).size / 1024)} KB`);
for (const file of (await readdir(source)).filter((f) => f.endsWith('.png')).sort()) {
    const name = file.replace(/\.png$/, '');
    for (const width of widths) {
        const out = path.join(target, `${name}-${width}.webp`);
        await sharp(path.join(source, file)).resize({ width }).webp({ quality: 72, effort: 6 }).toFile(out);
        await report(out);
    }
    const card = path.join(cutoutDir, `${name}.webp`);
    await (await cutout(file, 900)).webp({ quality: 82, alphaQuality: 90, effort: 6 }).toFile(card);
    await report(card);
    const web = path.join(target, `${name}-cutout-960.webp`);
    await (await cutout(file, 960)).webp({ quality: 74, alphaQuality: 80, effort: 6 }).toFile(web);
    await report(web);
}
