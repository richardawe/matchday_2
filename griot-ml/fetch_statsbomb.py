"""Download StatsBomb open data matches (events) for training.
Usage: python3 fetch_statsbomb.py [max matches per season] [competition name filter]
Writes data/statsbomb/<match id>.json = {"match": <matches.json entry>, "events": [...], "lineups": [...]}.
StatsBomb open data is free to use with attribution under StatsBomb's own terms: read
https://github.com/statsbomb/open-data (LICENSE.pdf) before shipping anything trained on it,
and credit "Data: StatsBomb" wherever the model is described."""
import json, sys, urllib.request
from pathlib import Path

RAW = 'https://raw.githubusercontent.com/statsbomb/open-data/master/data'
# Men's football with full event data that looks like a modern league or tournament.
DEFAULT = ['Premier League', 'La Liga', 'Ligue 1', 'Serie A', '1. Bundesliga', 'FIFA World Cup', 'UEFA Euro',
           'African Cup of Nations', 'Champions League', 'Copa America']


def get(path):
    with urllib.request.urlopen(f'{RAW}/{path}', timeout=60) as r:
        return json.load(r)


def main(limit=40, name=None):
    out = Path('data/statsbomb')
    out.mkdir(parents=True, exist_ok=True)
    wanted = [name] if name else DEFAULT
    saved = 0
    for comp in get('competitions.json'):
        if comp['competition_name'] not in wanted or comp.get('competition_gender') != 'male':
            continue
        matches = get(f"matches/{comp['competition_id']}/{comp['season_id']}.json")[:limit]
        for m in matches:
            target = out / f"{m['match_id']}.json"
            if target.exists():
                continue
            target.write_text(json.dumps({'match': m, 'events': get(f"events/{m['match_id']}.json"),
                                          'lineups': get(f"lineups/{m['match_id']}.json")}))
            saved += 1
        print(f"{comp['competition_name']} {comp['season_name']}: {len(matches)} matches")
    print(f'{saved} new matches in {out}')


if __name__ == '__main__':
    main(int(sys.argv[1]) if len(sys.argv) > 1 else 40, sys.argv[2] if len(sys.argv) > 2 else None)
