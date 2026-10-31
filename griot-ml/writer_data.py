"""The writer's input and its fact check, shared with the site (keep in step with
matchday-africa/app/Services/Griot/GriotWriter.php: input() and check()).

Input: {"kind", "army", "opp_army", "home", "away", "clock", "score", "facts"}; output: {"headline", "line"}.
The check rejects a caption that uses a number, or a capitalised word mid-sentence, that the input does not
contain, or that is too long. The writer may phrase things freely; it may not invent."""
import json, re
from pathlib import Path

# Club -> army names from the site's config/armies.php, so captions name the same armies as the site.
ARMIES = {}
_armies_php = Path(__file__).resolve().parent.parent / 'matchday-africa/config/armies.php'
if _armies_php.exists():
    ARMIES = dict(re.findall(r"'([a-z0-9 &.'-]+)' => \['army' => '([^']+)'", _armies_php.read_text()))


def army(team):
    key = re.sub(r'\s+', ' ', re.sub(r'\bfc\b', '', (team or '').lower())).strip()
    return ARMIES.get(key) or ARMIES.get(re.sub(r'\ba?fc\b', '', key).strip())

COMMON = {'The', 'A', 'An', 'And', 'But', 'Still', 'Full', 'Half', 'Time', 'Half-time', 'Full-time', 'No', 'One', 'Two', 'Three',
          'VAR', 'Hear', 'Let', 'Listen', 'What', 'So', 'Now', 'Then', 'Yet', 'Even', 'Again', 'All', 'It', 'He', 'His', 'They',
          'Their', 'I', 'Not', 'Wood', 'Denied', 'Off', 'On', 'In', 'At', 'From', 'For', 'To', 'Of', 'With', 'By', 'As', 'This', 'That'}


def writer_input(kind, army, opp_army, home, away, clock, score, facts):
    return {'kind': kind, 'army': army, 'opp_army': opp_army, 'home': home, 'away': away, 'clock': clock, 'score': score,
            'facts': {k: v for k, v in facts.items() if v not in (None, '', False, [])}}


def prompt(inp):
    return 'Write the Griot\'s caption as JSON {"headline","line"} for this moment:\n' + json.dumps(inp, ensure_ascii=False, sort_keys=True)


def check(inp, out, max_headline=8, max_line=26):
    """[] when the caption passes, else the reasons it fails."""
    problems = []
    if not isinstance(out, dict) or not isinstance(out.get('headline'), str) or not isinstance(out.get('line'), str):
        return ['not a {headline, line} object']
    head, line = out['headline'].strip(), out['line'].strip()
    if not head or not line:
        problems.append('empty')
    if len(head.split()) > max_headline or len(line.split()) > max_line:
        problems.append('too long')
    source = json.dumps(inp, ensure_ascii=False)
    numbers = set(re.findall(r'\d+', source))
    for n in re.findall(r'\d+', head + ' ' + line):
        if n not in numbers:
            problems.append(f'number {n} not in the facts')
    words = set(re.findall(r"[A-Za-zÀ-ÿ'’-]+", source))
    for text in (head, line):
        tokens = re.findall(r"[A-Za-zÀ-ÿ'’-]+|[.!?:]", text)
        for i, w in enumerate(tokens):
            if not w[0].isupper() or w in words or w in COMMON:
                continue
            # A capital at a sentence start is fine for an ordinary word, not for a name: a name runs on
            # into another capitalised word ("Mohamed Salah").
            starts = i == 0 or tokens[i - 1] in '.!?:'
            follows = tokens[i + 1] if i + 1 < len(tokens) else ''
            if starts and not (follows[:1].isupper()):
                continue
            problems.append(f'name {w} not in the facts')
    return problems


def parity(out_path):
    """Rewrite the PHP test's writer-parity.json: prompts and fact-check verdicts on fixed cases."""
    inputs = [
        writer_input('goal', 'Norse Vikings', 'Byzantine Cataphracts', 'Liverpool', 'Brighton & Hove Albion', '21′', '2–0',
                     {'player': 'Alexander Isak', 'assist': 'Bradley Barcola', 'angle': 'extend', 'zone': 'six_yard', 'shots_before': 2}),
        writer_input('pressure', 'Byzantine Cataphracts', 'Norse Vikings', 'Liverpool', 'Brighton & Hove Albion', '45+2′', '2–0',
                     {'shots': 4, 'on_target': 1, 'span': 2, 'players': ['Olivier Boscagli', 'Charalambos Kostoulas', 'Diego Gómez'], 'quote': 'say "hi"\\ok'}),
    ]
    outputs = [
        {'headline': 'The Norse Vikings press on', 'line': 'Alexander Isak volleys home, Bradley Barcola the giver. 2–0.'},
        {'headline': 'Isak again!', 'line': 'Mohamed Salah makes it 3–0 in the 22nd minute.'},
        {'headline': 'Glorious!', 'line': 'Brilliant from Isak. Superb.'},
        {'headline': 'The Cataphracts storm the gate', 'line': 'Four shots in 2 minutes. Diego Gómez and Kostoulas cannot find the way.'},
    ]
    cases = [{'input': i, 'prompt': prompt(i), 'checks': [{'output': o, 'problems': check(i, o)} for o in outputs]} for i in inputs]
    Path(out_path).write_text(json.dumps(cases, ensure_ascii=False, indent=1))
    print(f'{len(cases)} cases -> {out_path}')


if __name__ == '__main__':
    import sys
    if sys.argv[1:2] == ['parity']:
        parity(Path(__file__).resolve().parent.parent / 'matchday-africa/tests/Fixtures/griot/writer-parity.json')
    else:
        print(__doc__)
