#!/usr/bin/env python3
"""Builds resources/css/cartoon-auto.css: the cartoon look's mechanical overrides.

The classic look hard-codes dark backgrounds and light text in many rules of app.css. For every
such rule this writes the same selector under .md-cartoon with a light surface or ink text, keeping
its @media wrapper, so the whole site flips without editing app.css. Hand-made component styles
live in resources/css/cartoon.css, which loads after this file and wins where they overlap.

    python3 scripts/build-cartoon-css.py   (run again whenever app.css or the war styles change)

It also builds resources/js/war/war-cartoon-auto.css from the Matchday War app's own styles
(war.css, game.css), scoped to body.war-cartoon.
"""
import re
import pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'resources/css/app.css'
OUT = ROOT / 'resources/css/cartoon-auto.css'

DARK_VARS = ('--md-ink', '--md-panel', '--md-panel-soft')


BEIGE = ('#e8e1d3', '#e9e1d2', '#f5efe4', '#f4ecdd', '#f6f0e5', '#d8cdbc', '#d1c7b7', '#c8bdad')


def lum(r, g, b):
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def colours(value):
    """(luminance, alpha) of each literal colour in a CSS value."""
    out = []
    for h in re.findall(r'#([0-9a-fA-F]{3,8})\b', value):
        if len(h) in (3, 4):
            r, g, b = (int(c * 2, 16) for c in h[:3])
        else:
            r, g, b = (int(h[i:i + 2], 16) for i in (0, 2, 4))
        a = int(h[6:8], 16) / 255 if len(h) == 8 else 1
        out.append((lum(r, g, b), a))
    for m in re.finditer(r'rgba?\(([^)]*)\)', value):
        parts = [p.strip() for p in re.split(r'[,\s/]+', m.group(1)) if p.strip()]
        try:
            r, g, b = (float(p) for p in parts[:3])
            a = float(parts[3]) if len(parts) > 3 else 1
        except ValueError:
            continue
        out.append((lum(r, g, b), a))
    return out


def is_dark_bg(value):
    if any(f'var({v})' in value for v in DARK_VARS):
        return True
    return any(l < 75 and a > 0.5 for l, a in colours(value))


def is_light_text(value):
    if 'var(--md-paper)' in value:
        return False  # the token flips to ink already
    c = colours(value)
    return bool(c) and all(l > 170 for l, _ in c)


SCOPE = '.md-cartoon'


def scope(selector):
    selector = selector.strip()
    if SCOPE == '.war-cartoon':
        if selector.startswith((':root', 'html')):
            return None
        if selector.startswith('body'):
            return 'body.war-cartoon' + selector[4:]
        return 'body.war-cartoon ' + selector
    if selector.startswith(':root') or selector.startswith('html'):
        return None
    if selector.startswith('body.matchday-shell') or selector.startswith('.matchday-shell'):
        return re.sub(r'^(body)?\.matchday-shell', 'body.md-cartoon', selector)
    if selector.startswith('body'):
        return 'body.md-cartoon' + selector[4:]
    return '.md-cartoon ' + selector


def blocks(css):
    """Yields ('rule', prelude, body) and ('at', prelude, inner) at one nesting level."""
    i, n = 0, len(css)
    while i < n:
        j = css.find('{', i)
        if j < 0:
            return
        prelude = css[i:j].strip()
        # Skip comments and statements such as @import ...; before the block.
        prelude = re.sub(r'/\*.*?\*/', '', prelude, flags=re.S)
        while ';' in prelude and prelude.lstrip().startswith('@') and not prelude.lstrip().startswith(('@media', '@supports', '@layer')):
            prelude = prelude.split(';', 1)[1].strip()
        depth, k = 1, j + 1
        while k < n and depth:
            if css[k] == '{':
                depth += 1
            elif css[k] == '}':
                depth -= 1
            k += 1
        body = css[j + 1:k - 1]
        if prelude.startswith('@'):
            yield 'at', prelude, body
        else:
            yield 'rule', prelude, body
        i = k


def walk(css, wrappers=()):
    for kind, prelude, body in blocks(css):
        if kind == 'at':
            name = prelude.split()[0]
            if name in ('@media', '@supports'):
                yield from walk(body, wrappers + (prelude,))
            elif name == '@layer':
                yield from walk(body, wrappers)
            continue
        out = []
        for decl in body.split(';'):
            if ':' not in decl:
                continue
            prop, value = (s.strip() for s in decl.split(':', 1))
            prop = prop.lower()
            # An !important original only yields to an !important override.
            imp = '!important' if '!important' in value else ''
            pseudo = re.search(r'::?(before|after)\b', prelude)
            if prop in ('font', 'font-family') and 'georgia' in value.lower():
                # The classic serif becomes the cartoon display face.
                out.append('font-family:var(--c-display)' + imp)
                out.append('font-weight:400' + imp)
            elif prop in ('background', 'background-color') and any(h in value.lower() for h in BEIGE):
                # The classic parchment panels become the cartoon paper.
                out.append(('background:none' if pseudo else 'background:var(--c-paper)') + imp)
            elif prop in ('background', 'background-color', 'background-image') and is_dark_bg(value):
                out.append(('background:none' if pseudo else 'background:var(--c-surface)') + imp)
            elif prop == 'color' and is_light_text(value):
                out.append('color:var(--c-ink)' + imp)
            elif prop in ('border', 'border-color', 'border-top', 'border-bottom', 'border-left', 'border-right') and colours(value) and all(l < 90 for l, _ in colours(value)):
                out.append((f'{prop}-color:var(--c-ink)' if prop not in ('border', 'border-color') else 'border-color:var(--c-ink)') + imp)
        if not out:
            continue
        sels = [s for s in (scope(x) for x in prelude.split(',')) if s]
        if sels:
            yield wrappers, ','.join(sels), ';'.join(dict.fromkeys(out))


def build(sources, out, scope_class):
    global SCOPE
    SCOPE = scope_class
    lines = [f'/* Generated by scripts/build-cartoon-css.py from {", ".join(s.name for s in sources)}: do not edit by hand. */']
    count = 0
    for src in sources:
        for wrappers, selector, decls in walk(src.read_text()):
            rule = f'{selector}{{{decls}}}'
            for w in reversed(wrappers):
                rule = f'{w}{{{rule}}}'
            lines.append(rule)
            count += 1
    out.write_text('\n'.join(lines) + '\n')
    print(f'{count} rules -> {out.relative_to(ROOT)}')


def main():
    build([SRC], OUT, '.md-cartoon')
    war = ROOT / 'resources/js/war'
    build([war / 'war.css', war / 'game.css'], war / 'war-cartoon-auto.css', '.war-cartoon')


if __name__ == '__main__':
    main()
