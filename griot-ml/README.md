# Griot ML

The small, open models behind the live Griot timeline on matchday.africa. Training only: nothing here is
deployed. The site's side lives in `matchday-africa/app/Services/Griot/` and `matchday-africa/radio/griot-*.mjs`.

| Part | Model | Size | Licence | Where it runs |
|---|---|---|---|---|
| Read the feed | plain code (`griot_plays.py` ⇄ `EspnCommentaryMapper.php`) | – | – | PHP, every minute |
| Pick the moments | logistic model over 20 features (`train_picker.py`) | a few KB of JSON | ours | PHP (`MomentPicker`) |
| Write the caption | Qwen3-0.6B + LoRA (`train_writer.py`) | ~0.5 GB at 4-bit | Apache 2.0 | Node, transformers.js (`radio/griot-writer.mjs`) |
| Speak it | Kokoro-82M, two voices blended into the Griot, plus FFmpeg and a synthesised talking drum | ~90 MB | Apache 2.0 | Node (`radio/griot-voice.mjs`) |

Without a trained picker the strict rules decide; without a trained writer the Griot templates
(`GriotTeller.php`, four voices) speak. Each trained piece is only switched on when it beats what it replaces.

## Data: ESPN and StatsBomb, one format

Both sources become the same *standard plays* (types, sides, pitch positions, details; see the docstring in
`griot_plays.py`), so the models learn from exactly what the site feeds them live.

```bash
python3 fetch_espn.py eng.1 2025-08-15 2026-05-25          # finished Premier League matches with commentary
python3 fetch_statsbomb.py 60                               # StatsBomb open data (leagues, World Cup, AFCON, Euros…)
python3 build_dataset.py                                    # -> data/matches.jsonl (plays + picker candidates)
```

- **ESPN**: unofficial feed; its terms ban automated use. The owner accepted that for the site (AGENTS.md rule 7).
  The provider's commentary text is never sent to the teacher, never used as a caption and never published.
- **StatsBomb open data**: free with attribution under StatsBomb's terms. Read their LICENSE.pdf before shipping a
  model trained on it, and credit "Data: StatsBomb" wherever the model is described.

## Labels: an open teacher

```bash
OPENROUTER_API_KEY=… python3 label.py 400 8          # up to 400 matches, stop at $8
```

The teacher (`deepseek/deepseek-v4-pro` by default, as in requstory-ml) reads each match's plays, keeps the optional
moments a fan would want and writes a Griot caption for every post. Captions must use only the facts given.

## Train

```bash
python3 train_picker.py                       # -> models/picker.json; prints held-out accuracy vs the strict rules
python3 train_writer.py data                  # fact-checked caption pairs -> data/writer.jsonl
python3 train_writer.py train                 # LoRA on Qwen3-0.6B (GPU)   -> models/writer-merged
python3 train_writer.py eval                  # held-out fact-check pass rate
python3 train_writer.py export                # 4-bit ONNX for transformers.js -> models/griot-writer
```

`python3 train_picker.py --rules-as-labels` runs the whole picker pipeline without labels (a smoke test).

## Ship

1. Picker: copy `models/picker.json` to the server's `storage/app/griot/picker.json`. Only when the held-out
   accuracy beats the rules.
2. Writer: copy `models/griot-writer/` to `storage/app/griot/writer/`, set `GRIOT_WRITER=true`. Every caption is
   fact-checked on the site; a rejected one falls back to the templates.

## Keep the two sides in step

`python3 griot_plays.py parity` regenerates `matchday-africa/tests/Fixtures/griot/picker-parity.json`;
the PHP test fails if `MomentPicker.php` and `griot_plays.py` disagree. `writer-parity.json` does the same for
the writer's prompt and fact check (`writer_data.py` ⇄ `GriotWriter.php`).
