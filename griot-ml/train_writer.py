"""Fine-tune the Griot's writer: Qwen3-0.6B (Apache 2.0) learns to turn a moment's facts into a caption.
Usage:
  python3 train_writer.py data      # build data/writer.jsonl from the teacher's captions (fact-checked)
  python3 train_writer.py train     # LoRA fine-tune, merge -> models/writer-merged   (needs a GPU; ~20 min on one T4)
  python3 train_writer.py export    # ONNX + 4-bit for transformers.js -> models/griot-writer
  python3 train_writer.py eval      # held-out matches: fact-check pass rate of the trained writer
Then copy models/griot-writer to the server (storage/app/griot/writer) and set GRIOT_WRITER=true.
Extra packages for train/export/eval: pip install -r requirements-writer.txt"""
import hashlib, json, subprocess, sys
from pathlib import Path
from writer_data import writer_input, prompt, check, army

BASE = 'Qwen/Qwen3-0.6B'


def is_test(match_id):
    return int(hashlib.md5(match_id.encode()).hexdigest(), 16) % 5 == 0


def clock_text(minute, extra):
    return f"{int(minute)}′" if not extra else f"{int(minute)}+{int(extra)}′"


def build():
    matches = {json.loads(l)['id']: json.loads(l) for l in open('data/matches.jsonl')}
    rows, rejected = [], 0
    for f in sorted(Path('data/labels').glob('*.json')):
        if f.name.startswith('_'):
            continue
        lab = json.loads(f.read_text())
        m = matches.get(lab['id'])
        if not m:
            continue
        home, away = m['home'], m['away']
        armies = {'home': army(home) or home, 'away': army(away) or away}
        moments = [(str(i), c) for i, c in enumerate(m['candidates']) if i in set(lab['keep'])]
        moments += [(f"a{i}", a) for i, a in enumerate(lab['always'])]
        for ref, moment in moments:
            kind, side = moment['kind'], moment['side']
            caption = lab['captions'].get(ref)
            if not caption:
                continue
            score = moment['score']
            minute = moment['minute']
            inp = writer_input(kind, armies.get(side, ''), armies['away' if side == 'home' else 'home'], home, away,
                               clock_text(minute, moment.get('extra_minute')), f"{score['home']}–{score['away']}", moment.get('facts', {}))
            if check(inp, caption):
                rejected += 1
                continue
            rows.append({'match': lab['id'], 'input': inp, 'output': {'headline': caption['headline'].strip(), 'line': caption['line'].strip()}})
    Path('data/writer.jsonl').write_text(''.join(json.dumps(r, ensure_ascii=False) + '\n' for r in rows))
    print(f'{len(rows)} captions kept, {rejected} failed the fact check -> data/writer.jsonl')


def messages(row, with_answer=True):
    msgs = [{'role': 'user', 'content': prompt(row['input'])}]
    if with_answer:
        msgs.append({'role': 'assistant', 'content': json.dumps(row['output'], ensure_ascii=False)})
    return msgs


def train():
    import torch
    from datasets import Dataset
    from peft import LoraConfig, get_peft_model
    from transformers import AutoModelForCausalLM, AutoTokenizer, Trainer, TrainingArguments, DataCollatorForLanguageModeling
    rows = [json.loads(l) for l in open('data/writer.jsonl')]
    train_rows = [r for r in rows if not is_test(r['match'])]
    tok = AutoTokenizer.from_pretrained(BASE)
    model = AutoModelForCausalLM.from_pretrained(BASE, torch_dtype=torch.bfloat16 if torch.cuda.is_available() else torch.float32)
    model = get_peft_model(model, LoraConfig(r=16, lora_alpha=32, lora_dropout=0.05, target_modules=['q_proj', 'k_proj', 'v_proj', 'o_proj', 'gate_proj', 'up_proj', 'down_proj'], task_type='CAUSAL_LM'))
    text = [tok.apply_chat_template(messages(r), tokenize=False, enable_thinking=False) for r in train_rows]
    data = Dataset.from_dict({'text': text}).map(lambda b: tok(b['text'], truncation=True, max_length=512), batched=True, remove_columns=['text'])
    Trainer(model=model, train_dataset=data, data_collator=DataCollatorForLanguageModeling(tok, mlm=False),
            args=TrainingArguments('models/writer-lora', num_train_epochs=3, per_device_train_batch_size=8, gradient_accumulation_steps=2,
                                   learning_rate=2e-4, logging_steps=20, save_strategy='no', bf16=torch.cuda.is_available(), report_to=[])).train()
    merged = model.merge_and_unload()
    merged.save_pretrained('models/writer-merged')
    tok.save_pretrained('models/writer-merged')
    print(f'{len(train_rows)} training captions -> models/writer-merged')


def export():
    out = Path('models/griot-writer')
    subprocess.run(['optimum-cli', 'export', 'onnx', '--model', 'models/writer-merged', '--task', 'text-generation-with-past', str(out / 'onnx-fp32')], check=True)
    # transformers.js layout: config + tokenizer at the top, onnx/model_q4.onnx beside them.
    from onnxruntime.quantization import matmul_4bits_quantizer, quant_utils
    import onnx, shutil
    (out / 'onnx').mkdir(parents=True, exist_ok=True)
    model = onnx.load(str(out / 'onnx-fp32/model.onnx'), load_external_data=True)
    q = matmul_4bits_quantizer.MatMul4BitsQuantizer(model, block_size=32, is_symmetric=True)
    q.process()
    quant_utils.save_model(q.model.model, str(out / 'onnx/model_q4.onnx'), use_external_data_format=True)
    for f in (out / 'onnx-fp32').iterdir():
        if f.suffix == '.json' or f.name.endswith('.txt') or f.name == 'tokenizer.json':
            shutil.copy(f, out / f.name)
    shutil.rmtree(out / 'onnx-fp32')
    print(f'-> {out} (copy to the server and set GRIOT_WRITER=true)')


def evaluate():
    from transformers import AutoModelForCausalLM, AutoTokenizer
    rows = [json.loads(l) for l in open('data/writer.jsonl') if is_test(json.loads(l)['match'])]
    tok = AutoTokenizer.from_pretrained('models/writer-merged')
    model = AutoModelForCausalLM.from_pretrained('models/writer-merged')
    passed = 0
    for r in rows:
        ids = tok.apply_chat_template(messages(r, False), add_generation_prompt=True, enable_thinking=False, return_tensors='pt')
        text = tok.decode(model.generate(ids, max_new_tokens=90, do_sample=False)[0][ids.shape[1]:], skip_special_tokens=True)
        try:
            ok = not check(r['input'], json.loads(text[text.index('{'):text.rindex('}') + 1]))
        except ValueError:
            ok = False
        passed += ok
    print(f'{passed}/{len(rows)} held-out captions pass the fact check ({passed / max(1, len(rows)):.0%})')


if __name__ == '__main__':
    steps = {'data': build, 'train': train, 'export': export, 'eval': evaluate}
    if len(sys.argv) < 2 or sys.argv[1] not in steps:
        sys.exit(__doc__)
    steps[sys.argv[1]]()
