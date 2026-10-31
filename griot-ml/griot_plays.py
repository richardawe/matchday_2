"""Standard Griot plays and moment candidates, shared by every griot-ml script.

Mirrors the site (keep them in step):
  matchday-africa/app/Services/Espn/EspnCommentaryMapper.php  -> espn_plays()
  matchday-africa/app/Services/Griot/MomentPicker.php         -> candidates(), features()
`python3 griot_plays.py parity` rewrites tests/Fixtures/griot/picker-parity.json, which the PHP test
compares against, so a drift between the two shows up as a failing test.

Play: {provider, provider_key, seq, period, minute, extra_minute, type, side, player, player2, x, y, detail, text}
Types: goal own_goal penalty_goal penalty_miss shot_saved shot_missed shot_blocked woodwork var_no_goal
       var_decision yellow_card red_card substitution foul handball corner offside delay period_start
       period_end added_time other
x/y: 0-100 along the attacking side's direction (100 = the goal it attacks). Goals (own goals too) carry
the side credited with the goal.
"""
import json, math, re, sys
from pathlib import Path

FEATURES = ['kind_big_chance', 'kind_pressure', 'kind_injury', 'kind_woodwork', 'minute', 'late', 'second_half',
            'score_diff', 'level', 'zone_six', 'zone_box', 'zone_outside', 'x', 'saved', 'close', 'penalty', 'head',
            'side_shots', 'opp_shots', 'side_on_target']
SHOTS = ['goal', 'own_goal', 'penalty_goal', 'penalty_miss', 'shot_saved', 'shot_missed', 'shot_blocked', 'woodwork']
GOALS = ['goal', 'own_goal', 'penalty_goal']
ON_TARGET = ['shot_saved', 'goal', 'penalty_goal']
SETTINGS = {'pressure_shots': 3, 'pressure_minutes': 6, 'cooldown_minutes': 10}

# ---------------------------------------------------------------- ESPN

ESPN_TYPES = {
    'goal': 'goal', 'own-goal': 'own_goal', 'penalty---scored': 'penalty_goal',
    'penalty---missed': 'penalty_miss', 'penalty---saved': 'penalty_miss',
    'shot-on-target': 'shot_saved', 'shot-off-target': 'shot_missed', 'shot-blocked': 'shot_blocked',
    'shot-hit-woodwork': 'woodwork', 'deleted-after-review': 'var_no_goal',
    'yellow-card': 'yellow_card', 'red-card': 'red_card', 'substitution': 'substitution',
    'foul': 'foul', 'handball': 'handball', 'corner-awarded': 'corner', 'offside': 'offside',
    'start-delay': 'delay', 'end-delay': 'other', 'kickoff': 'period_start', 'start-2nd-half': 'period_start',
    'halftime': 'period_end', 'end-regular-time': 'period_end',
}


def _norm(name):
    return re.sub(r'[^a-z0-9]+', ' ', (name or '').replace('&', 'and').lower()).strip()


def _untyped(text):
    if re.match(r'^(First|Second) Half begins', text, re.I):
        return 'period_start'
    if re.match(r'^(First|Second) Half ends|^Match ends', text, re.I):
        return 'period_end'
    if re.match(r'^Fourth official has announced \d+ minutes?', text, re.I):
        return 'added_time'
    return None


def _espn_detail(text, type_):
    t = text.lower()
    zone = ('six_yard' if 'six yard box' in t or 'very close range' in t else
            'outside' if 'outside the box' in t or 'long range' in t or 'more than 35 yards' in t else
            'box' if 'the box' in t or 'difficult angle' in t else None)
    body = 'head' if 'header' in t else 'left' if 'left footed' in t else 'right' if 'right footed' in t else None
    detail = {
        'zone': zone, 'body': body,
        'free_kick': 'free kick' in t and type_ in ('shot_saved', 'shot_missed', 'shot_blocked', 'woodwork', 'goal'),
        'penalty': 'penalty' in t and type_ != 'foul',
        'close': 'close, but' in t or 'just a bit' in t,
        'injury': 'injury' in t,
        'cross': 'with a cross' in t,
    }
    detail = {k: v for k, v in detail.items() if v}
    if type_ == 'added_time':
        m = re.search(r'(\d+) minutes?', text)
        if m:
            detail['minutes'] = int(m.group(1))
    return detail


def espn_plays(summary):
    sides = {}
    for c in summary.get('header', {}).get('competitions', [{}])[0].get('competitors', []):
        sides[_norm(c.get('team', {}).get('displayName'))] = 'away' if c.get('homeAway') == 'away' else 'home'
    plays, seen, period = [], set(), 1
    for line in summary.get('commentary', []):
        text = (line.get('text') or '').strip()
        play = line.get('play') or {}
        slug = (play.get('type') or {}).get('type', '') or ''
        pid = str(play.get('id', '') or '')
        if pid and pid in seen:
            continue
        seen.add(pid)
        type_ = ESPN_TYPES.get(slug) or ('goal' if slug.startswith('goal') else 'var_decision' if slug.startswith('var') else None)
        type_ = type_ or _untyped(text)
        if not type_:
            continue
        period = int((play.get('period') or {}).get('number') or (2 if re.match(r'^Second Half|^Match ends', text, re.I) else period))
        display = (line.get('time') or {}).get('displayValue', '') or ''
        m = re.search(r"(\d+)'?(?:\s*\+\s*(\d+))?", display)
        if m:
            minute, extra = int(m.group(1)), int(m.group(2)) if m.group(2) else None
        else:
            minute, extra = ((45 if type_ == 'period_start' else 90) if period >= 2 else (0 if type_ == 'period_start' else 45)), None
        names = [p['athlete']['displayName'] for p in play.get('participants', []) if (p.get('athlete') or {}).get('displayName')]
        assist = re.search(r'Assisted by ([^.]+?)(?: with| following|\.)', text)
        x, y = play.get('fieldPositionX'), play.get('fieldPositionY')
        plays.append({
            'provider': 'espn', 'provider_key': pid or f"seq:{line.get('sequence', len(plays))}",
            'seq': int(line.get('sequence', len(plays))), 'period': period, 'minute': minute, 'extra_minute': extra,
            'type': type_, 'side': sides.get(_norm((play.get('team') or {}).get('displayName'))),
            'player': names[0] if names else None,
            'player2': names[1] if len(names) > 1 else (assist.group(1).strip() if assist else None),
            'x': round(float(x), 1) if isinstance(x, (int, float)) else None,
            'y': round(float(y), 1) if isinstance(y, (int, float)) else None,
            'detail': _espn_detail(text, type_), 'text': text,
        })
    return plays

# ---------------------------------------------------------------- StatsBomb open data

SB_OUTCOMES = {'Goal': 'goal', 'Saved': 'shot_saved', 'Saved To Post': 'shot_saved', 'Saved to Post': 'shot_saved',
               'Off T': 'shot_missed', 'Wayward': 'shot_missed', 'Saved Off Target': 'shot_missed',
               'Post': 'woodwork', 'Blocked': 'shot_blocked'}


def _sb_clock(period, minute):
    """StatsBomb counts elapsed minutes (45:30 is minute 45); the commentary clock shows 45+1."""
    shown = minute + 1
    edge = {1: 45, 2: 90, 3: 105, 4: 120}.get(period)
    if edge and shown > edge:
        return edge, shown - edge
    return shown, None


def _sb_zone(x, y):
    if x is None:
        return None
    if x >= 114 and 30 <= y <= 50:
        return 'six_yard'
    if x >= 102 and 18 <= y <= 62:
        return 'box'
    return 'outside'


def statsbomb_plays(match, events, lineups=None):
    """One StatsBomb match (its matches.json entry, events.json and optional lineups.json) as standard plays.
    Players go by their known name (lineup nickname, "Mohamed Salah") like ESPN's, not their full legal name.
    The penalty shootout (period 5) is left out, as on the site."""
    home = match['home_team']['home_team_name']
    known = {p['player_id']: p.get('player_nickname') or p['player_name'] for team in (lineups or []) for p in team.get('lineup', [])}
    name_of = lambda person: known.get((person or {}).get('id')) or (person or {}).get('name')
    plays = []
    own_goal_players = {}
    for e in events:
        if e['type']['name'] == 'Own Goal Against':
            own_goal_players[(e['period'], e['minute'])] = name_of(e.get('player'))
    for i, e in enumerate(events):
        name = e['type']['name']
        if e['period'] >= 5:
            continue
        side = 'home' if e.get('team', {}).get('name') == home else 'away'
        player = name_of(e.get('player'))
        loc = e.get('location') or [None, None]
        minute, extra = _sb_clock(e['period'], e['minute'])
        type_, player2, detail = None, None, {}
        if name == 'Shot':
            shot = e['shot']
            sb_type = shot.get('type', {}).get('name')
            outcome = shot.get('outcome', {}).get('name')
            type_ = SB_OUTCOMES.get(outcome, 'shot_missed')
            if sb_type == 'Penalty':
                type_ = 'penalty_goal' if outcome == 'Goal' else 'penalty_miss'
                detail['penalty'] = True
            if sb_type == 'Free Kick':
                detail['free_kick'] = True
            body = shot.get('body_part', {}).get('name', '')
            detail['body'] = 'head' if body == 'Head' else 'left' if body.startswith('Left') else 'right' if body.startswith('Right') else None
            detail['zone'] = _sb_zone(loc[0], loc[1])
        elif name == 'Own Goal For':
            type_, player = 'own_goal', own_goal_players.get((e['period'], e['minute']))
        elif name == 'Substitution':
            type_ = 'substitution'
            player, player2 = name_of(e['substitution'].get('replacement')), player
            if 'injury' in (e['substitution'].get('outcome', {}).get('name', '') or '').lower():
                detail['injury'] = True
        elif name in ('Foul Committed', 'Bad Behaviour'):
            card = ((e.get('foul_committed') or e.get('bad_behaviour') or {}).get('card') or {}).get('name')
            type_ = 'red_card' if card in ('Red Card', 'Second Yellow') else 'yellow_card' if card == 'Yellow Card' else ('foul' if name == 'Foul Committed' else None)
        elif name == 'Half Start' and e.get('team', {}).get('name') == home:
            type_, side = 'period_start', None
            minute, extra = (0, None) if e['period'] == 1 else ({2: 45, 3: 90, 4: 105}.get(e['period'], 0), None)
        elif name == 'Half End' and e.get('team', {}).get('name') == home:
            type_, side = 'period_end', None
        elif name == 'Pass' and (e.get('pass') or {}).get('type', {}).get('name') == 'Corner':
            type_ = 'corner'
        if not type_:
            continue
        if type_ in SHOTS and type_ != 'own_goal':
            # Who set it up: the pass this shot came from.
            key_pass = e['shot'].get('key_pass_id') if name == 'Shot' else None
            if key_pass:
                player2 = next((name_of(p.get('player')) for p in events if p.get('id') == key_pass), None)
        plays.append({
            'provider': 'statsbomb', 'provider_key': e['id'], 'seq': i, 'period': e['period'], 'minute': minute, 'extra_minute': extra,
            'type': type_, 'side': side, 'player': player, 'player2': player2,
            'x': round(loc[0] / 1.2, 1) if loc[0] is not None else None, 'y': round(loc[1] / 0.8, 1) if loc[1] is not None else None,
            'detail': {k: v for k, v in detail.items() if v}, 'text': None,
        })
    return plays

# ---------------------------------------------------------------- candidates and features (MomentPicker.php)


def clock(p):
    return float(p['minute']) + float(p.get('extra_minute') or 0)


def wtime(p):
    return clock(p) + 20 * (max(1, int(p['period'])) - 1)


def php_round(v, n=3):
    # PHP rounds half away from zero; Python's round() rounds half to even.
    q = 10 ** n
    return math.copysign(math.floor(abs(v) * q + 0.5) / q, v)


def features(kind, p, diff, ctx):
    zone = (p.get('detail') or {}).get('zone')
    c = clock(p)
    d = p.get('detail') or {}
    return {
        'kind_big_chance': float(kind == 'big_chance'), 'kind_pressure': float(kind == 'pressure'),
        'kind_injury': float(kind == 'injury'), 'kind_woodwork': float(kind == 'woodwork'),
        'minute': php_round(min(1.1, c / 90)), 'late': float(c >= 80), 'second_half': float(int(p['period']) >= 2),
        'score_diff': php_round(max(-3, min(3, diff)) / 3), 'level': float(diff == 0),
        'zone_six': float(zone == 'six_yard'), 'zone_box': float(zone == 'box'), 'zone_outside': float(zone == 'outside'),
        'x': php_round(float(p['x']) / 100) if p.get('x') is not None else 0.0,
        'saved': float(p['type'] == 'shot_saved'), 'close': float(bool(d.get('close'))),
        'penalty': float(p['type'] == 'penalty_miss' or bool(d.get('penalty'))),
        'head': float(d.get('body') == 'head'),
        'side_shots': min(1, ctx['side_shots'] / 5), 'opp_shots': min(1, ctx['opp_shots'] / 5),
        'side_on_target': min(1, ctx['side_on_target'] / 3),
    }


def candidates(plays, settings=SETTINGS):
    """Optional moments with features and the strict-rule verdict (the always-kept kinds are skipped)."""
    out, score, shots, spells = [], {'home': 0, 'away': 0}, [], {}
    pm = settings['pressure_minutes']
    for p in plays:
        t, side = p['type'], p.get('side')
        if t in ('period_start', 'period_end') or not side:
            continue
        opp = 'away' if side == 'home' else 'home'
        time = wtime(p)
        before = dict(score)
        d = p.get('detail') or {}
        if t in SHOTS:
            shots.append((time, side, t, p['provider_key'], p.get('player')))
        recent = [s for s in shots if time - pm <= s[0] <= time]
        side_shots = [s for s in recent if s[1] == side]
        ctx = {'side_shots': len(side_shots), 'opp_shots': len([s for s in recent if s[1] == opp]),
               'side_on_target': len([s for s in side_shots if s[2] in ON_TARGET])}
        diff = before[side] - before[opp]
        key = lambda kind: f"{kind}:{p['provider']}:{p['provider_key']}"
        if t in GOALS:
            score[side] += 1
            spells[side] = None
            continue
        common = {'side': side, 'time': time, 'minute': p['minute'], 'extra_minute': p.get('extra_minute'), 'play': p, 'score': dict(score)}
        clean = lambda facts: {k: v for k, v in facts.items() if v not in (None, False, '')}
        if t == 'woodwork':
            out.append({**common, 'key': key('woodwork'), 'kind': 'woodwork', 'strict': True, 'features': features('woodwork', p, diff, ctx),
                        'facts': clean({'player': p.get('player'), 'zone': d.get('zone'), 'body': d.get('body')})})
        elif t == 'substitution' and d.get('injury'):
            out.append({**common, 'key': key('injury'), 'kind': 'injury', 'strict': True, 'features': features('injury', p, diff, ctx),
                        'facts': clean({'player_on': p.get('player'), 'player_off': p.get('player2')})})
        elif t in ('shot_saved', 'shot_missed', 'penalty_miss') and (t == 'penalty_miss' or d.get('zone') in ('six_yard', 'box') or d.get('close')):
            strict = t == 'penalty_miss' or d.get('zone') == 'six_yard' or (t == 'shot_saved' and float(p.get('x') or 0) >= 92)
            out.append({**common, 'key': key('big_chance'), 'kind': 'big_chance', 'strict': strict, 'features': features('big_chance', p, diff, ctx),
                        'facts': clean({'player': p.get('player'), 'outcome': 'saved' if t == 'shot_saved' else 'penalty_missed' if t == 'penalty_miss' else 'missed',
                                        'zone': d.get('zone'), 'body': d.get('body'), 'close': bool(d.get('close'))})})
        if t in SHOTS:
            spell = spells.get(side)
            if not spell or time - spell['last'] > pm or time - spell['start'] >= 2 * pm:
                spell = {'first': p['provider_key'], 'start': time, 'shots': []}
            spell['last'] = time
            spell['shots'].append((p['provider_key'], p.get('player'), t in ON_TARGET))
            spells[side] = spell
            n = len(spell['shots'])
            if n >= 2:
                pkey = f"pressure:{side}:{p['provider']}:{spell['first']}"
                out = [c for c in out if c['key'] != pkey]
                on = len([s for s in spell['shots'] if s[2]])
                players = list(dict.fromkeys(s[1] for s in spell['shots'] if s[1]))
                out.append({**common, 'key': pkey, 'kind': 'pressure', 'strict': n >= settings['pressure_shots'],
                            'facts': {'shots': n, 'on_target': on, 'span': max(1, math.ceil(time - spell['start'])), 'players': players},
                            'features': features('pressure', p, diff, {'side_shots': n, 'opp_shots': ctx['opp_shots'], 'side_on_target': on})})
    return out


def angle(before, against, minute):
    if before == 0 and against == 0:
        return 'late_winner' if minute >= 85 else 'opener'
    if before + 1 == against:
        return 'equaliser'
    if before == against:
        return 'late_winner' if minute >= 85 else 'lead'
    return 'extend' if before > against else 'consolation'


def always_moments(plays):
    """Goals, red cards and VAR calls with the facts the site stores (for the writer's training data)."""
    out, score = [], {'home': 0, 'away': 0}
    for p in plays:
        t, side = p['type'], p.get('side')
        if not side:
            continue
        opp = 'away' if side == 'home' else 'home'
        d = p.get('detail') or {}
        key = f"{p['provider']}:{p['provider_key']}"
        if t in GOALS:
            before = dict(score)
            score[side] += 1
            facts = {'player': p.get('player'), 'assist': None if t == 'own_goal' else p.get('player2'), 'own_goal': t == 'own_goal',
                     'penalty': t == 'penalty_goal' or bool(d.get('penalty')), 'angle': angle(before[side], before[opp], clock(p)),
                     'zone': d.get('zone'), 'body': d.get('body')}
            out.append({'key': 'goal:' + key, 'kind': 'goal', 'side': side, 'minute': p['minute'], 'extra_minute': p.get('extra_minute'),
                        'score': dict(score), 'facts': {k: v for k, v in facts.items() if v not in (None, False)}})
        elif t in ('red_card', 'var_no_goal'):
            out.append({'key': ('red_card:' if t == 'red_card' else 'var:') + key, 'kind': 'red_card' if t == 'red_card' else 'var', 'side': side,
                        'minute': p['minute'], 'extra_minute': p.get('extra_minute'), 'score': dict(score), 'facts': {'player': p.get('player')}})
    return out


def parity(fixture_path, out_path):
    plays = espn_plays(json.load(open(fixture_path)))
    rows = [{'key': c['key'], 'strict': c['strict'], 'features': {k: php_round(float(v)) for k, v in c['features'].items()}} for c in candidates(plays)]
    Path(out_path).write_text(json.dumps({'source': Path(fixture_path).name, 'candidates': rows}, indent=1) + '\n')
    print(f'{len(rows)} candidates -> {out_path}')


if __name__ == '__main__':
    if sys.argv[1:2] == ['parity']:
        root = Path(__file__).resolve().parent.parent / 'matchday-africa/tests/Fixtures/griot'
        parity(root / 'summary-liverpool-brighton.json', root / 'picker-parity.json')
    else:
        print(__doc__)
