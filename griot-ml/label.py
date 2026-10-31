"""Teacher labels: a large open-weights model reads each match and says which optional moments a fan's live
timeline should post, and writes a Griot caption for every post (the writer's training data).
Usage: OPENROUTER_API_KEY=... python3 label.py <max matches> <budget USD> [workers]
Reads data/matches.jsonl (build_dataset.py); writes data/labels/<match id>.json and logs spend to
data/labels/_spend.jsonl, stopping at the budget. Same teacher and settings as requstory-ml.
The teacher sees structured plays only (never the provider's commentary text), so ESPN and StatsBomb
matches look the same to it."""
import json, os, sys, threading, urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from griot_plays import always_moments
from writer_data import army

MODEL = os.environ.get('TEACHER', 'deepseek/deepseek-v4-pro')
KEY = os.environ.get('OPENROUTER_API_KEY') or sys.exit('Set OPENROUTER_API_KEY')
OUT = Path('data/labels')
OUT.mkdir(parents=True, exist_ok=True)
SPEND = OUT / '_spend.jsonl'
lock = threading.Lock()
spent = sum(json.loads(l)['cost'] for l in SPEND.open()) if SPEND.exists() else 0.0

RULES = """You are the editor of the live match timeline on matchday.africa, a football site for African fans.
The timeline is told by the Griot, a West African storyteller and keeper of the match's story: warm, rhythmic,
proud, a little playful, never sarcastic. Teams are called by their army names when they have one.

1. KEEP: from the numbered candidates, choose the optional moments a fan following on a phone would want.
   A good timeline has roughly 6-12 optional posts across a match (plus every goal, red card and VAR call,
   which are always posted). Prefer moments that changed the feel of the match: real chances, a spell of
   pressure, the woodwork, injuries to important players. Drop routine shots and repeats of the same story.
2. WRITE: for every kept candidate AND every always-posted moment, write a caption in the Griot's English:
   {"headline": at most 8 words, "line": at most 26 words}.
   Use ONLY names and numbers that appear in that moment's facts or the match header. Never invent
   a player, a minute, a score, a statistic or a distance. Use the score_after given; do not recount.

Reply with JSON only: {"keep": [candidate numbers], "captions": {"<number or always id>": {"headline": "...", "line": "..."}}}"""


def play_line(p):
    clock = f"{p['minute']}'" + (f"+{p['extra_minute']}" if p.get('extra_minute') else '')
    bits = [clock, p.get('side') or '', p['type'], p.get('player') or '']
    d = p.get('detail') or {}
    extra = ', '.join(x for x in (d.get('zone'), d.get('body'), 'close' if d.get('close') else None, 'injury' if d.get('injury') else None) if x)
    if p.get('player2'):
        bits.append(f"(with {p['player2']})")
    return ' '.join(b for b in bits if b) + (f' [{extra}]' if extra else '')


def prompt(m):
    keep_types = {'goal', 'own_goal', 'penalty_goal', 'penalty_miss', 'shot_saved', 'shot_missed', 'shot_blocked', 'woodwork',
                  'var_no_goal', 'red_card', 'yellow_card', 'substitution', 'period_start', 'period_end'}
    log = '\n'.join(play_line(p) for p in m['plays'] if p['type'] in keep_types)
    header = {'home': m['home'], 'away': m['away'], 'home_army': army(m['home']), 'away_army': army(m['away'])}
    cands = [{'n': i, 'kind': c['kind'], 'side': c['side'], 'minute': round(c['time'], 1), 'score_after': c['score'], 'facts': c.get('facts', {})}
             for i, c in enumerate(m['candidates'])]
    always = [{'id': f"a{i}", 'kind': a['kind'], 'side': a['side'], 'minute': a['minute'], 'extra_minute': a['extra_minute'],
               'score_after': a['score'], 'facts': a['facts']} for i, a in enumerate(always_moments(m['plays']))]
    return (f"Match: {json.dumps(header)}\n\nPlays:\n{log}\n\nCandidates:\n{json.dumps(cands, ensure_ascii=False)}\n\n"
            f"Always posted:\n{json.dumps(always, ensure_ascii=False)}"), always


def call(messages):
    global spent
    body = json.dumps({'model': MODEL, 'messages': messages, 'temperature': 0.3, 'max_tokens': 6000,
                       'response_format': {'type': 'json_object'}, 'usage': {'include': True}, 'reasoning': {'enabled': False},
                       'provider': {'order': ['streamlake', 'baidu', 'parasail'], 'sort': 'price', 'require_parameters': True}}).encode()
    req = urllib.request.Request('https://openrouter.ai/api/v1/chat/completions', body,
                                 {'Authorization': f'Bearer {KEY}', 'Content-Type': 'application/json', 'X-Title': 'Matchday Griot labelling'})
    with urllib.request.urlopen(req, timeout=180) as r:
        data = json.load(r)
    cost = float(data.get('usage', {}).get('cost', 0) or 0)
    with lock:
        spent += cost
        with SPEND.open('a') as fh:
            fh.write(json.dumps({'cost': cost}) + '\n')
    return json.loads(data['choices'][0]['message']['content'])


def label(m, budget):
    target = OUT / f"{m['id'].replace(':', '_')}.json"
    if target.exists() or spent >= budget:
        return
    text, always = prompt(m)
    try:
        answer = call([{'role': 'system', 'content': RULES}, {'role': 'user', 'content': text}])
    except Exception as e:
        print('  failed', m['id'], e)
        return
    keep = {int(n) for n in answer.get('keep', []) if str(n).isdigit() and int(n) < len(m['candidates'])}
    target.write_text(json.dumps({'id': m['id'], 'teacher': MODEL, 'keep': sorted(keep),
                                  'captions': answer.get('captions', {}), 'always': always}, ensure_ascii=False, indent=1))
    print(f"{m['id']}: kept {len(keep)}/{len(m['candidates'])} (${spent:.3f})")


def main(limit, budget, workers=4):
    matches = [json.loads(l) for l in open('data/matches.jsonl')][:limit]
    with ThreadPoolExecutor(workers) as pool:
        list(pool.map(lambda m: label(m, budget), matches))
    print(f'Spent ${spent:.3f} in total')


if __name__ == '__main__':
    if len(sys.argv) < 3:
        sys.exit(__doc__)
    main(int(sys.argv[1]), float(sys.argv[2]), int(sys.argv[3]) if len(sys.argv) > 3 else 4)
