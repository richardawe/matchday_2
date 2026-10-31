"""Train the moment picker: a logistic model over MomentPicker's 20 features, from the teacher's keep/drop labels.
Usage: python3 train_picker.py [--rules-as-labels]
Reads data/matches.jsonl and data/labels/*.json; writes models/picker.json, which the site loads from
GRIOT_PICKER_MODEL (default storage/app/griot/picker.json). Prints held-out accuracy for the model and
for the strict rules on the same matches, so it only ships when it beats them.
--rules-as-labels trains on the strict rules' own verdicts: a smoke test of the pipeline, not a model to ship.
Needs only numpy."""
import hashlib, json, sys, time
from pathlib import Path
import numpy as np
from griot_plays import FEATURES


def is_test(match_id):
    return int(hashlib.md5(match_id.encode()).hexdigest(), 16) % 5 == 0


def load(rules_as_labels):
    rows = []
    for line in open('data/matches.jsonl'):
        m = json.loads(line)
        if rules_as_labels:
            keep = {i for i, c in enumerate(m['candidates']) if c['strict']}
        else:
            f = Path('data/labels') / f"{m['id'].replace(':', '_')}.json"
            if not f.exists():
                continue
            keep = set(json.loads(f.read_text())['keep'])
        for i, c in enumerate(m['candidates']):
            rows.append((m['id'], m['source'], [float(c['features'][k]) for k in FEATURES], int(i in keep), int(c['strict'])))
    return rows


def fit(X, y, l2=1.0, steps=3000, lr=0.5):
    w, b = np.zeros(X.shape[1]), 0.0
    pos = max(1, y.sum())
    weight = np.where(y == 1, len(y) / (2 * pos), len(y) / (2 * max(1, len(y) - pos)))  # balance keep/drop
    for _ in range(steps):
        p = 1 / (1 + np.exp(-(X @ w + b)))
        g = (p - y) * weight
        w -= lr * (X.T @ g / len(y) + l2 * w / len(y))
        b -= lr * g.mean()
    return w, b


def report(name, pred, y):
    tp, fp, fn = int(((pred == 1) & (y == 1)).sum()), int(((pred == 1) & (y == 0)).sum()), int(((pred == 0) & (y == 1)).sum())
    precision, recall = tp / max(1, tp + fp), tp / max(1, tp + fn)
    print(f'{name:<8} accuracy {(pred == y).mean():.3f}  precision {precision:.3f}  recall {recall:.3f}')
    return (pred == y).mean()


def main():
    rules_as_labels = '--rules-as-labels' in sys.argv
    rows = load(rules_as_labels)
    if not rows:
        sys.exit('No labelled candidates: run build_dataset.py and label.py first (or pass --rules-as-labels).')
    train = [r for r in rows if not is_test(r[0])]
    test = [r for r in rows if is_test(r[0])] or train
    X, y = np.array([r[2] for r in train]), np.array([r[3] for r in train])
    w, b = fit(X, y)
    Xt, yt = np.array([r[2] for r in test]), np.array([r[3] for r in test])
    print(f'{len({r[0] for r in train})} training matches, {len({r[0] for r in test})} held out, {len(rows)} candidates, '
          f'sources: {sorted({r[1] for r in rows})}')
    model_acc = report('model', ((1 / (1 + np.exp(-(Xt @ w + b)))) >= 0.5).astype(int), yt)
    rules_acc = report('rules', np.array([r[4] for r in test]), yt)
    out = Path('models')
    out.mkdir(exist_ok=True)
    model = {'version': time.strftime('%Y%m%d'), 'features': FEATURES, 'bias': round(float(b), 5),
             'weights': [round(float(v), 5) for v in w], 'labels': 'rules' if rules_as_labels else 'teacher',
             'held_out': {'model_accuracy': round(float(model_acc), 3), 'rules_accuracy': round(float(rules_acc), 3), 'candidates': len(test)}}
    (out / 'picker.json').write_text(json.dumps(model, indent=1))
    print('-> models/picker.json' + ('  (smoke test only: trained on the rules)' if rules_as_labels else ''))
    if not rules_as_labels and model_acc <= rules_acc:
        print('The model does not beat the rules on held-out matches: do not ship it.')


if __name__ == '__main__':
    main()
