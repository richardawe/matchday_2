// The Griot's writer: the fine-tuned Qwen3-0.6B (griot-ml/train_writer.py) turns a moment's facts into a caption.
//   node griot-writer.mjs --jobs jobs.json --model dir      (jobs: [{id, prompt}])
// Prints JSON [{id, text}]: the raw reply. The site parses it and fact-checks it (GriotWriter.php) before use,
// so a wrong or invented caption is simply dropped and the Griot templates speak instead.
// --model may also be a Hugging Face model id (e.g. onnx-community/Qwen3-0.6B-ONNX) for a smoke test.
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export function reply(text) {
    // Qwen3 may still open an empty <think></think>; keep only the JSON object.
    const clean = text.replace(/<think>[\s\S]*?<\/think>/g, '');
    const start = clean.indexOf('{'), end = clean.lastIndexOf('}');
    return start > -1 && end > start ? clean.slice(start, end + 1) : clean.trim();
}

async function main() {
    const arg = (name) => { const i = process.argv.indexOf(`--${name}`); return i > -1 ? process.argv[i + 1] : null; };
    if (!arg('jobs') || !arg('model')) throw new Error('Usage: node griot-writer.mjs --jobs jobs.json --model dir');
    const jobs = JSON.parse(await readFile(arg('jobs'), 'utf8'));
    if (!jobs.length) { console.log('[]'); return; }
    const { env, AutoTokenizer, AutoModelForCausalLM } = await import('@huggingface/transformers');
    if (process.env.RADIO_TTS_CACHE) env.cacheDir = process.env.RADIO_TTS_CACHE;
    const model = arg('model');
    const local = model.startsWith('/') || model.startsWith('.');
    if (local) { env.localModelPath = path.dirname(path.resolve(model)); env.allowRemoteModels = false; }
    const id = local ? path.basename(path.resolve(model)) : model;
    const tokenizer = await AutoTokenizer.from_pretrained(id);
    const llm = await AutoModelForCausalLM.from_pretrained(id, { dtype: process.env.GRIOT_WRITER_DTYPE || 'q4', device: 'cpu',
        session_options: { intraOpNumThreads: 2, interOpNumThreads: 1 } });
    const out = [];
    for (const job of jobs) {
        const inputs = tokenizer.apply_chat_template([{ role: 'user', content: job.prompt }],
            { add_generation_prompt: true, return_dict: true, enable_thinking: false });
        const tokens = await llm.generate({ ...inputs, max_new_tokens: 90, do_sample: false });
        const text = tokenizer.batch_decode(tokens.slice(null, [inputs.input_ids.dims.at(-1), null]), { skip_special_tokens: true })[0];
        out.push({ id: job.id, text: reply(text) });
    }
    console.log(JSON.stringify(out));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    main().catch((error) => { console.error('Griot writer: ' + error.message); process.exitCode = 1; });
}
