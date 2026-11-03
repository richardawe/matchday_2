// Builds web-sized copies of the crest-free army statues (config/armies.php):
// public/war/downloads/rights-safe/<art>.png -> public/war/army/<art>-<width>.webp
// The 1.9 MB originals stay as the source for server-rendered story cards.
// Usage: node scripts/build-armies.mjs
import { mkdir, readFile, readdir, stat } from 'node:fs/promises';
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

// Solid cut-outs for bright backgrounds (reel style v2). The brightness cut-out above lets dark
// armour turn see-through, which only works on black. Here: the statue is every pixel brighter
// than the backdrop, minus small specks of dust, plus every hole enclosed by its outline (dark
// cloth, shadowed limbs), with a one-pixel feathered edge.
// Brighter than this (0–255, brightest channel) is statue; the backdrops sit at 0–10 with dust above.
const SOLID_LEVEL = Number(process.env.SOLID_LEVEL ?? 16);
const INK = 7; // outline width in pixels at 900 px wide
// Statues photographed against a lit, smoky backdrop need a higher level or they keep a halo.
const SOLID_LEVELS = { 'napoleonic-grenadier': 30, 'norse-viking': 22, 'northumbrian-raider': 34, 'numidian-cavalry': 30, 'saxon-fyrd': 20, 'venetian-marine': 20, 'white-rose-yorkist': 30 };
async function solid(input, width, level = SOLID_LEVEL) {
    const { data, info } = await sharp(input).resize({ width }).removeAlpha().raw().toBuffer({ resolveWithObject: true });
    const { width: w, height: h } = info;
    const n = w * h;
    const mask = new Uint8Array(n);
    for (let p = 0; p < n; p++) mask[p] = Math.max(data[p * 3], data[p * 3 + 1], data[p * 3 + 2]) > level ? 1 : 0;
    const around = (p, visit) => {
        const x = p % w;
        if (x > 0) visit(p - 1);
        if (x < w - 1) visit(p + 1);
        if (p >= w) visit(p - w);
        if (p < n - w) visit(p + w);
    };
    // Specks: bright patches smaller than 0.4% of the picture are backdrop dust.
    const seen = new Uint8Array(n);
    for (let start = 0; start < n; start++) {
        if (!mask[start] || seen[start]) continue;
        const patch = [start];
        seen[start] = 1;
        for (let i = 0; i < patch.length; i++) around(patch[i], (q) => { if (mask[q] && !seen[q]) { seen[q] = 1; patch.push(q); } });
        if (patch.length < n * 0.004) for (const p of patch) mask[p] = 0;
    }
    // Outside: backdrop reachable from the edges without crossing the statue. The rest is statue.
    const outside = new Uint8Array(n);
    const stack = [];
    for (let x = 0; x < w; x++) stack.push(x, n - w + x);
    for (let y = 0; y < h; y++) stack.push(y * w, y * w + w - 1);
    while (stack.length) {
        const p = stack.pop();
        if (outside[p] || mask[p]) continue;
        outside[p] = 1;
        around(p, (q) => stack.push(q));
    }
    // The comic ink line is baked in (cheaper than drawing it on every video frame): the statue's
    // shape grown by INK pixels (alternating cross and square steps, roughly round), painted black.
    const body = new Uint8Array(n);
    for (let p = 0; p < n; p++) body[p] = outside[p] ? 0 : 1;
    let grown = body;
    for (let step = 0; step < INK; step++) {
        const next = new Uint8Array(grown);
        const square = step % 2 === 1;
        for (let p = 0; p < n; p++) {
            if (grown[p]) continue;
            const x = p % w;
            const left = x > 0, right = x < w - 1, up = p >= w, down = p < n - w;
            if ((left && grown[p - 1]) || (right && grown[p + 1]) || (up && grown[p - w]) || (down && grown[p + w])
                || (square && ((up && left && grown[p - w - 1]) || (up && right && grown[p - w + 1]) || (down && left && grown[p + w - 1]) || (down && right && grown[p + w + 1])))) next[p] = 1;
        }
        grown = next;
    }
    const rgba = Buffer.alloc(n * 4);
    for (let p = 0; p < n; p++) {
        let a = 0;
        for (const q of [p, p - 1, p + 1, p - w, p + w]) a += q >= 0 && q < n && grown[q] ? 1 : 0;
        const ink = !body[p];
        rgba[p * 4] = ink ? 11 : data[p * 3]; rgba[p * 4 + 1] = ink ? 11 : data[p * 3 + 1]; rgba[p * 4 + 2] = ink ? 11 : data[p * 3 + 2];
        rgba[p * 4 + 3] = Math.round((a / 5) * 255);
    }
    return sharp(rgba, { raw: { width: w, height: h, channels: 4 } });
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
    const flat = path.join(target, `${name}-solid.webp`);
    await (await solid(path.join(source, file), 900, SOLID_LEVELS[name])).webp({ quality: 82, alphaQuality: 90, effort: 6 }).toFile(flat);
    await report(flat);
}

// Mood thumbnails for the cartoon site (timeline, live cards, tables): war/army/moods/<art>-<mood>.webp,
// 320 px squares, head to waist, on the club's sky (warrior, victory) or a gloomy one (dejected,
// defeated). The same treatments as the reel. Sky colours: resources/armies/colours.json (php artisan armies:colours).
const moodDir = path.join(target, 'moods');
await mkdir(moodDir, { recursive: true });
const clubColours = JSON.parse(await readFile(path.join(root, 'resources/armies/colours.json'), 'utf8'));
const sky = (colour, mood) => {
    const gloomy = mood === 'dejected' || mood === 'defeated';
    const base = mood === 'defeated' ? '#7a7066' : mood === 'dejected' ? '#5a7aa5' : colour;
    const rays = gloomy ? '' : Array.from({ length: 18 }, (_, i) => {
        const a = (i * 20) * Math.PI / 180, b = (i * 20 + 9) * Math.PI / 180;
        return `<polygon points="160,200 ${160 + 400 * Math.cos(a)},${200 + 400 * Math.sin(a)} ${160 + 400 * Math.cos(b)},${200 + 400 * Math.sin(b)}" fill="#fff" fill-opacity="${mood === 'victory' ? 0.3 : 0.15}"/>`;
    }).join('');
    const rain = mood === 'dejected' ? Array.from({ length: 22 }, (_, i) => `<line x1="${(i * 53) % 320}" y1="${(i * 97) % 300}" x2="${(i * 53) % 320 + 8}" y2="${(i * 97) % 300 + 40}" stroke="#dbe7ff" stroke-opacity=".55" stroke-width="3"/>`).join('') : '';
    const glow = mood === 'victory' ? '<circle cx="160" cy="150" r="120" fill="#ffd23f" fill-opacity=".55"/>' : '';
    return Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320"><defs><radialGradient id="g" cx="50%" cy="60%" r="75%"><stop offset="0" stop-color="${base}"/><stop offset="1" stop-color="#000" stop-opacity=".55"/></radialGradient></defs><rect width="100%" height="100%" fill="${base}"/><rect width="100%" height="100%" fill="url(#g)"/>${rays}${glow}${rain}</svg>`);
};
for (const [art, colour] of Object.entries(clubColours)) {
    const file = path.join(target, `${art}-solid.webp`);
    if (!(await stat(file).catch(() => null))) continue;
    for (const mood of ['warrior', 'victory', 'dejected', 'defeated']) {
        let statue = sharp(file);
        const meta = await statue.metadata();
        statue = statue.extract({ left: 0, top: 0, width: meta.width, height: Math.round(meta.height * 0.62) }).resize({ height: 330 });
        if (mood === 'victory') statue = statue.modulate({ brightness: 1.12, saturation: 1.5 });
        if (mood === 'dejected') statue = statue.modulate({ brightness: 0.88, saturation: 0.35 });
        if (mood === 'defeated') statue = statue.modulate({ brightness: 0.72, saturation: 0 }).rotate(12, { background: { r: 0, g: 0, b: 0, alpha: 0 } });
        const top = mood === 'defeated' ? 40 : mood === 'dejected' ? 24 : 4;
        const fitted = await sharp(await statue.png().toBuffer()).resize({ width: 300, height: 320 - top, fit: 'inside' }).png().toBuffer();
        const fm = await sharp(fitted).metadata();
        const out = path.join(moodDir, `${art}-${mood}.webp`);
        await sharp(sky(colour, mood)).composite([{ input: fitted, left: Math.round((320 - fm.width) / 2), top }]).webp({ quality: 80 }).toFile(out);
    }
    console.log(`war/army/moods/${art}-*.webp`);
}

// Mood poses for reel style v2: rights-safe/poses/<art>-<victory|dejected|defeated>.png, each a
// crest-free statue on black, reviewed and listed in config/armies.php `poses` before use.
// Solid cut-outs and poses are public (war/army) so the GitHub renderer can fetch them too.
const poseSource = path.join(source, 'poses');
const poseTarget = path.join(target, 'poses');
await mkdir(poseTarget, { recursive: true });
for (const file of (await readdir(poseSource).catch(() => [])).filter((f) => f.endsWith('.png')).sort()) {
    const out = path.join(poseTarget, file.replace(/\.png$/, '.webp'));
    await (await solid(path.join(poseSource, file), 900)).webp({ quality: 82, alphaQuality: 90, effort: 6 }).toFile(out);
    await report(out);
}
