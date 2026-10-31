"""Turn every downloaded match into standard plays and picker candidates.
Usage: python3 build_dataset.py
Writes data/matches.jsonl: one line per match {id, source, home, away, plays, candidates}.
Both sources go through the same code as the site (griot_plays.py), so the picker learns
from exactly what it will see live."""
import json
from pathlib import Path
from griot_plays import espn_plays, statsbomb_plays, candidates


def espn_matches():
    for f in sorted(Path('data/espn').glob('*/*.json')):
        summary = json.loads(f.read_text())
        teams = {c['homeAway']: c['team']['displayName'] for c in summary['header']['competitions'][0]['competitors']}
        yield f'espn:{f.stem}', 'espn', teams.get('home'), teams.get('away'), espn_plays(summary)


def statsbomb_matches():
    for f in sorted(Path('data/statsbomb').glob('*.json')):
        data = json.loads(f.read_text())
        m = data['match']
        yield f'statsbomb:{f.stem}', 'statsbomb', m['home_team']['home_team_name'], m['away_team']['away_team_name'], statsbomb_plays(m, data['events'], data.get('lineups'))


def main():
    out = Path('data/matches.jsonl')
    count = {'espn': 0, 'statsbomb': 0}
    cands = 0
    with out.open('w') as fh:
        for mid, source, home, away, plays in [*espn_matches(), *statsbomb_matches()]:
            if not plays:
                continue
            cs = candidates(plays)
            for c in cs:
                c.pop('play', None)
            fh.write(json.dumps({'id': mid, 'source': source, 'home': home, 'away': away,
                                 'plays': [{k: v for k, v in p.items() if k != 'text'} for p in plays], 'candidates': cs}) + '\n')
            count[source] += 1
            cands += len(cs)
    print(f"{count['espn']} ESPN + {count['statsbomb']} StatsBomb matches, {cands} candidates -> {out}")


if __name__ == '__main__':
    main()
