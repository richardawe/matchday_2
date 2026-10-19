// One-off server check: can this machine render the reel?
//   cd reel && npm ci && node check.mjs
// Reports Node, memory and CPUs, makes sure Remotion's headless Chrome is installed, then
// renders a 2-second test clip and says how long a full 20-second reel would take.
import { ensureBrowser } from '@remotion/renderer';
import { readFile, stat } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { renderReel } from './render.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const ok = (msg) => console.log(`  ✓ ${msg}`);
const fail = (msg, hint) => { console.log(`  ✗ ${msg}`); if (hint) console.log(`    ${hint}`); process.exit(1); };

console.log('Matchday reel: server check');
const major = Number(process.versions.node.split('.')[0]);
major >= 18 ? ok(`Node ${process.versions.node}`) : fail(`Node ${process.versions.node} is too old`, 'Remotion needs Node 18 or newer. In cPanel: Software → Setup Node.js App → pick 20 or 22.');
ok(`${os.cpus().length} CPU cores, ${Math.round(os.totalmem() / 1024 ** 3 * 10) / 10} GB memory (${Math.round(os.freemem() / 1024 ** 2)} MB free)`);
if (os.totalmem() < 1.5 * 1024 ** 3) console.log('    Note: under 1.5 GB memory; set REEL_CONCURRENCY=1 if renders fail.');

try {
    if (process.env.REEL_BROWSER) ok(`Using Chrome at ${process.env.REEL_BROWSER}`);
    else { await ensureBrowser(); ok('Headless Chrome installed'); }
} catch (e) {
    fail('Could not install headless Chrome', e.message);
}

const sample = JSON.parse(await readFile(path.join(here, 'src/sample.json'), 'utf8'));
sample.home.art_path = path.join(here, '../resources/armies/roman-legion.webp');
sample.away.art_path = path.join(here, '../resources/armies/ottoman-janissary.webp');
const out = path.join(os.tmpdir(), 'matchday-reel-check.mp4');
try {
    const { seconds } = await renderReel(sample, out, { composition: 'Check', log: () => {} });
    ok(`Rendered a 2-second test clip in ${seconds}s (${Math.round((await stat(out)).size / 1024)} KB)`);
    console.log(`  → A full 20-second reel should take roughly ${Math.ceil((seconds * 10 * 2.5) / 60)} minutes here.`);
    console.log('PASS: this server can render the reel. Set REEL_RENDERER=server in .env.');
} catch (e) {
    const missing = /error while loading shared libraries: ([^:]+)/.exec(e.message);
    fail('Test render failed', missing
        ? `Chrome needs the system library ${missing[1]}. Ask your host to install Chrome's dependencies, or use REEL_RENDERER=github.`
        : `${e.message.split('\n')[0]}  (or use REEL_RENDERER=github)`);
}
