"""Download finished ESPN match summaries (with commentary) for training.
Usage: python3 fetch_espn.py <league slug, e.g. eng.1> <from YYYY-MM-DD> <to YYYY-MM-DD> [pause seconds]
Writes data/espn/<league>/<event id>.json and skips files already there.
ESPN's feed is unofficial and its terms ban automated use; the owner accepted that risk for
matchday.africa (AGENTS.md rule 7). Keep the pause, fetch only what training needs, and never
publish the provider's commentary text."""
import datetime as dt, json, sys, time, urllib.request
from pathlib import Path

BASE = 'https://site.api.espn.com/apis/site/v2/sports/soccer'


def get(url):
    for attempt in range(3):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'matchday-africa-griot-ml'}), timeout=20) as r:
                return json.load(r)
        except Exception as e:  # network blips: back off and retry
            if attempt == 2:
                print('  failed', url, e)
                return None
            time.sleep(2 ** (attempt + 1))


def main(league, start, end, pause=1.0):
    out = Path('data/espn') / league
    out.mkdir(parents=True, exist_ok=True)
    day, last = dt.date.fromisoformat(start), dt.date.fromisoformat(end)
    saved = 0
    while day <= last:
        board = get(f'{BASE}/{league}/scoreboard?dates={day:%Y%m%d}') or {}
        for event in board.get('events', []):
            status = event.get('status', {}).get('type', {})
            target = out / f"{event['id']}.json"
            if not status.get('completed') or target.exists():
                continue
            summary = get(f"{BASE}/{league}/summary?event={event['id']}")
            if summary and summary.get('commentary'):
                keep = {k: summary[k] for k in ('header', 'commentary', 'boxscore') if k in summary}
                target.write_text(json.dumps(keep))
                saved += 1
            time.sleep(pause)
        day += dt.timedelta(days=1)
        time.sleep(pause)
    print(f'{saved} new matches in {out}')


if __name__ == '__main__':
    if len(sys.argv) < 4:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2], sys.argv[3], float(sys.argv[4]) if len(sys.argv) > 4 else 1.0)
