#!/usr/bin/env python3
"""card#11046 / card#11058 — the state chip's three reviewed colours: contrast, every pair, CVD, AS DRAWN.

Reads the `--state-*` tokens OUT OF `server/public/css/mezzanine.css` (never transcribed) and measures:

  1. WCAG 2.x contrast of `--state-ink` on every fill AT FULL LIGHT — the bar the brief sets (≥ 4.5:1).
  2. CIEDE2000 between EVERY pair of the nine drawn states (36 pairs), under normal vision and the three
     dichromacies (Machado, Oliveira & Fernandes 2009, severity 1.0, in linear sRGB), in two conditions:
       · the TOKENS — the fills as the sheet declares them (what the sheet and the swatches show);
       · AS DRAWN, over EACH plank course — the chip as the desk group paints it (review r1 MAJOR-1): the
         group's § 7.3 lighting class (`painter.js:83`: dimmed = opacity .72, dark = .45, desaturated =
         `filter: saturate(.3)`), composited in sRGB over the plank the chip sits on — `planks.svg` lays
         two courses, #d6a46c and #cf9c63, and the chip may sit on either or on their seam (confirm review
         m3), so both are scored and the worse counts. CSS compositing and the shorthand filter functions
         both operate in sRGB (Filter Effects Module Level 1: "Filter Functions must operate in the sRGB
         color space", read 2026-10-03), and the saturate() matrix is the spec's. Which state takes which
         lighting is `desk-render.js:128-136`'s table;
       · the HOLLOW chip (confirm review m4): an unconfirmed seat's chip is paper with a dashed edge in the
         state's token, and its desk is ALWAYS dimmed (`desk-render.js:436`), so the edge the eye meets is
         the token at .72 over the plank, for every state alike.
  3. A per-state MINIMA table — for each reviewed state, condition × vision, the smallest ΔE2000 to any
     other drawn state and WHICH state — and an L* table, both printed and recorded, so the design cites
     this output and restates no figure (confirm review M2).
  4. The pairs under the BOUND, per condition, as a list `report()` returns — the self-test plants a
     duplicate through report() and requires it listed, in every condition and in the tokens alone
     (canon #9: a check that cannot fail is a decoration).
  5. `--check` — the GATE CI runs: the same acceptance the self-test holds the PROPOSED table to, applied to
     the SHEET read from `--repo`. It exits 1, naming each pair with its condition, vision and ΔE2000, when
     any pair involving a reviewed (quiet) state falls under the BOUND in any condition or vision.

    python3 tools/design/state-chip-colours.py [--repo <tree>, default the tree this file is in] [--json out.json] [--selftest] [--check]
"""
import argparse, json, math, os, re, sys
from pathlib import Path

ap = argparse.ArgumentParser()
ap.add_argument('--repo', default=str(Path(__file__).resolve().parents[2]))   # the tree this file lives in
ap.add_argument('--json', default=None)
ap.add_argument('--selftest', action='store_true')
ap.add_argument('--check', action='store_true')
a = ap.parse_args()

CSS = (Path(a.repo) / 'server/public/css/mezzanine.css').read_text()
TOK = dict(re.findall(r'--([a-z0-9_-]+):\s*(#[0-9a-fA-F]{6})\s*;', CSS))
DRAWN = ['working', 'idle', 'blocked', 'stalled', 'unknown', 'catching_up', 'stale', 'offline', 'disabled']  # retired: no desk
REVIEWED = ['stale', 'offline', 'disabled']
VISIONS = ('normal', 'protan', 'deutan', 'tritan')

# desk-render.js:128-136 — the lighting each state's desk group is painted with; painter.js:83 — the classes.
LIGHTING = {'working': 'full', 'idle': 'full', 'blocked': 'full', 'stalled': 'full', 'unknown': 'full',
            'catching_up': 'desaturated', 'stale': 'dimmed', 'offline': 'dark', 'disabled': 'dimmed'}
OPACITY = {'full': 1.0, 'desaturated': 1.0, 'dimmed': 0.72, 'dark': 0.45}
SATURATE = 0.3
PLANK = '#d6a46c'   # floor-plane/planks.svg: the upper course (the mock sheet's background)
PLANKS = {'upper': '#d6a46c', 'lower': '#cf9c63'}   # planks.svg:10-18 — the two courses the chip may sit on
HOLLOW_OPACITY = OPACITY['dimmed']                   # desk-render.js:436: an unconfirmed seat's desk is dimmed
CONDITIONS = ('tokens', 'hollow', 'drawn@upper', 'drawn@lower')

# The bound a pair must clear, CHOSEN: ΔE2000 5 — about twice the ≈ 2.3 just-noticeable difference, the low
# end of the "perceptible at a glance" band of the commonly cited Mokrzycki & Tatol (2011) survey table
# (recalled, not re-read for this run). What re-derives it: a viewing test on the real floor.
BOUND = 5.0

# ── the proposal (Fable, round 2, searched under the AS-DRAWN model) ─────────────────────────
PROPOSED = {
    'stale': '#b8d1d1',      # frost: pale cool ash, the lightest of the family — "not current"
    'offline': '#5398c3',    # dusk blue: the one hue that survives the dark desk's .45 wash over the plank
    'disabled': '#8188a4',   # slate violet: the darkest the ink floor allows with margin
}

# ── colour maths ─────────────────────────────────────────────────────────────────────────────
def hex2rgb(h):
    h = h.lstrip('#'); return tuple(int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))

def rgb2hex(c):
    return '#%02x%02x%02x' % tuple(max(0, min(255, round(v * 255))) for v in c)

def lin(v):
    return v / 12.92 if v <= 0.04045 else ((v + 0.055) / 1.055) ** 2.4

def gam(v):
    v = max(0.0, min(1.0, v))
    return 12.92 * v if v <= 0.0031308 else 1.055 * v ** (1 / 2.4) - 0.055

def luminance(c):
    r, g, b = (lin(v) for v in c); return 0.2126 * r + 0.7152 * g + 0.0722 * b

def contrast(c1, c2):
    l1, l2 = luminance(c1), luminance(c2)
    return (max(l1, l2) + 0.05) / (min(l1, l2) + 0.05)

def rgb2lab(c):
    r, g, b = (lin(v) for v in c)
    x = (0.4124564 * r + 0.3575761 * g + 0.1804375 * b) / 0.95047
    y = (0.2126729 * r + 0.7151522 * g + 0.0721750 * b) / 1.00000
    z = (0.0193339 * r + 0.1191920 * g + 0.9503041 * b) / 1.08883
    f = lambda t: t ** (1 / 3) if t > 216 / 24389 else (24389 / 27 * t + 16) / 116
    fx, fy, fz = f(x), f(y), f(z)
    return (116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz))

def de2000(lab1, lab2):
    L1, a1, b1 = lab1; L2, a2, b2 = lab2
    C1 = math.hypot(a1, b1); C2 = math.hypot(a2, b2); Cm = (C1 + C2) / 2
    G = 0.5 * (1 - math.sqrt(Cm ** 7 / (Cm ** 7 + 25 ** 7)))
    a1p, a2p = (1 + G) * a1, (1 + G) * a2
    C1p, C2p = math.hypot(a1p, b1), math.hypot(a2p, b2)
    h = lambda ap_, bp: (math.degrees(math.atan2(bp, ap_)) % 360) if (ap_ or bp) else 0
    h1, h2 = h(a1p, b1), h(a2p, b2)
    dL = L2 - L1; dC = C2p - C1p
    if C1p * C2p == 0: dh = 0
    else:
        dh = h2 - h1
        if dh > 180: dh -= 360
        elif dh < -180: dh += 360
    dH = 2 * math.sqrt(C1p * C2p) * math.sin(math.radians(dh / 2))
    Lm = (L1 + L2) / 2; Cmp = (C1p + C2p) / 2
    if C1p * C2p == 0: hm = h1 + h2
    else:
        hm = (h1 + h2) / 2
        if abs(h1 - h2) > 180: hm += 180 if hm < 180 else -180
    T = 1 - 0.17 * math.cos(math.radians(hm - 30)) + 0.24 * math.cos(math.radians(2 * hm)) + 0.32 * math.cos(math.radians(3 * hm + 6)) - 0.20 * math.cos(math.radians(4 * hm - 63))
    SL = 1 + 0.015 * (Lm - 50) ** 2 / math.sqrt(20 + (Lm - 50) ** 2)
    SC = 1 + 0.045 * Cmp; SH = 1 + 0.015 * Cmp * T
    RT = -2 * math.sqrt(Cmp ** 7 / (Cmp ** 7 + 25 ** 7)) * math.sin(math.radians(60 * math.exp(-((hm - 275) / 25) ** 2)))
    return math.sqrt((dL / SL) ** 2 + (dC / SC) ** 2 + (dH / SH) ** 2 + RT * (dC / SC) * (dH / SH))

# Machado, Oliveira & Fernandes 2009, Table 1, severity 1.0 — read from the authors' page on 2026-10-03
# (https://www.inf.ufrgs.br/~oliveira/pubs_files/CVD_Simulation/CVD_Simulation.html); applied in linear sRGB.
CVD = {
    'protan': [[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]],
    'deutan': [[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.011820, 0.042940, 0.968881]],
    'tritan': [[1.255528, -0.076749, -0.178779], [-0.078411, 0.930809, 0.147602], [0.004733, 0.691367, 0.303900]],
}

def simulate(c, kind):
    if kind == 'normal': return c
    r, g, b = (lin(v) for v in c); M = CVD[kind]
    return tuple(gam(M[i][0] * r + M[i][1] * g + M[i][2] * b) for i in range(3))

def saturate(c, s):
    """Filter Effects Level 1's saturate() matrix, in sRGB as the spec requires of the shorthand functions."""
    r, g, b = c
    M = [[0.213 + 0.787 * s, 0.715 - 0.715 * s, 0.072 - 0.072 * s],
         [0.213 - 0.213 * s, 0.715 + 0.285 * s, 0.072 - 0.072 * s],
         [0.213 - 0.213 * s, 0.715 - 0.715 * s, 0.072 + 0.928 * s]]
    return tuple(max(0.0, min(1.0, M[i][0] * r + M[i][1] * g + M[i][2] * b)) for i in range(3))

def as_drawn(c, state, over=PLANK):
    """The chip's fill as the desk group paints it: the lighting class, composited over the plank (sRGB)."""
    light = LIGHTING[state]
    if light == 'desaturated':
        c = saturate(c, SATURATE)
    alpha = OPACITY[light]; o = hex2rgb(over)
    return tuple(alpha * c[i] + (1 - alpha) * o[i] for i in range(3))

# ── the measurements ─────────────────────────────────────────────────────────────────────────
ink = hex2rgb(TOK['state-ink'])

def palette(overrides):
    return {s: overrides.get(s, TOK[f'state-{s}']) for s in DRAWN}

def hollow_edge(c, over=PLANK):
    """The hollow chip's dashed edge as seen: the token at the dimmed desk's opacity over the plank."""
    o = hex2rgb(over)
    return tuple(HOLLOW_OPACITY * c[i] + (1 - HOLLOW_OPACITY) * o[i] for i in range(3))

def seen(c, state, condition):
    if condition == 'tokens': return c
    if condition == 'hollow': return hollow_edge(c)
    course = condition.split('@')[1]
    return as_drawn(c, state, PLANKS[course])

def all_pairs(pal, condition):
    """{vision: {(s, t): ΔE2000}} over all 36 pairs, under one of CONDITIONS."""
    out = {}
    for v in VISIONS:
        lab = {s: rgb2lab(simulate(seen(hex2rgb(pal[s]), s, condition), v)) for s in DRAWN}
        out[v] = {(s, t): de2000(lab[s], lab[t]) for i, s in enumerate(DRAWN) for t in DRAWN[i + 1:]}
    return out

def minima(pairs):
    """For each reviewed state: condition → vision → (smallest ΔE2000 to any other drawn state, that state)."""
    out = {}
    for s in REVIEWED:
        out[s] = {}
        for cond in CONDITIONS:
            out[s][cond] = {}
            for v in VISIONS:
                best = min(((d, (t if u == s else u)) for (u, t), d in pairs[cond][v].items() if s in (u, t)), key=lambda x: x[0])
                out[s][cond][v] = (round(best[0], 1), best[1])
    return out

def report(name, pal, quiet=False):
    """Prints the tables; RETURNS the measurement — the fills' figures, every pair per condition and vision,
    and `below`: every pair under BOUND, tagged by whether it involves a reviewed state."""
    rows = {}
    if not quiet:
        print(f'\n== {name} ==')
        print(f"{'state':12} {'token':8} {'ink@full':>8} {'L*tok':>6}  {'drawn@upper':11} {'L*':>5} {'ink':>5}  {'drawn@lower':11} {'L*':>5}  {'hollow-edge':11} {'L*':>5}  lighting")
    for s in DRAWN:
        c = hex2rgb(pal[s]); up = as_drawn(c, s, PLANKS['upper']); lo = as_drawn(c, s, PLANKS['lower']); ho = hollow_edge(c)
        rows[s] = {'token': pal[s], 'ink_contrast': round(contrast(c, ink), 2), 'L': round(rgb2lab(c)[0], 1),
                   'drawn_upper': rgb2hex(up), 'L_drawn_upper': round(rgb2lab(up)[0], 1), 'ink_contrast_drawn_upper': round(contrast(up, as_drawn(ink, s, PLANKS['upper'])), 2),
                   'drawn_lower': rgb2hex(lo), 'L_drawn_lower': round(rgb2lab(lo)[0], 1),
                   'hollow_edge': rgb2hex(ho), 'L_hollow': round(rgb2lab(ho)[0], 1), 'lighting': LIGHTING[s]}
        if not quiet:
            r = rows[s]; flag = '' if r['ink_contrast'] >= 4.5 else '  <-- UNDER 4.5'
            print(f"{s:12} {pal[s]:8} {r['ink_contrast']:8.2f} {r['L']:6.1f}  {r['drawn_upper']:11} {r['L_drawn_upper']:5.1f} {r['ink_contrast_drawn_upper']:5.2f}  {r['drawn_lower']:11} {r['L_drawn_lower']:5.1f}  {r['hollow_edge']:11} {r['L_hollow']:5.1f}  {LIGHTING[s]}{flag}")
    pairs = {cond: all_pairs(pal, cond) for cond in CONDITIONS}
    below = []
    for cond in CONDITIONS:
        for v in VISIONS:
            for (s, t), d in pairs[cond][v].items():
                if d < BOUND:
                    below.append({'condition': cond, 'vision': v, 'pair': f'{s}/{t}', 'de': round(d, 2),
                                  'reviewed': s in REVIEWED or t in REVIEWED})
    mins = minima(pairs)
    if not quiet:
        for cond in CONDITIONS:
            print(f'\n  all 36 pairs, {cond.upper()} — the five smallest per vision (ΔE2000):')
            for v in VISIONS:
                worst = sorted(pairs[cond][v].items(), key=lambda kv: kv[1])[:5]
                print(f'    {v:7} ' + '  '.join(f'{s}/{t} {d:4.1f}' for (s, t), d in worst))
            rv = [b for b in below if b['condition'] == cond and b['reviewed']]
            lv = [b for b in below if b['condition'] == cond and not b['reviewed']]
            print(f'    under the bound {BOUND}: reviewed pairs {len(rv)} ' + ', '.join(f"{b['pair']} {b['de']} {b['vision']}" for b in rv)
                  + f' | live-only pairs {len(lv)} ' + ', '.join(f"{b['pair']} {b['de']} {b['vision']}" for b in lv))
        print(f'\n  MINIMA — per reviewed state, the smallest ΔE2000 to any other drawn state (and which), condition × vision:')
        print(f"    {'state':9} {'condition':12} " + ' '.join(f'{v:>18}' for v in VISIONS))
        for s in REVIEWED:
            for cond in CONDITIONS:
                print(f'    {s:9} {cond:12} ' + ' '.join(f"{d:5.1f} {t:>12}" for (d, t) in (mins[s][cond][v] for v in VISIONS)))
        rmin = {cond: min(d for s in REVIEWED for v in VISIONS for (d, t) in [mins[s][cond][v]]) for cond in CONDITIONS}
        print('  SENSITIVITY — the smallest reviewed minimum per condition: ' + ', '.join(f'{c} {d:.1f}' for c, d in rmin.items()))
    return {'fills': rows, 'pairs': {c: {v: {f'{s}/{t}': round(d, 1) for (s, t), d in pv.items()} for v, pv in pc.items()} for c, pc in pairs.items()},
            'minima': mins, 'below': below}

def reviewed_below(result):
    """The acceptance, UNFILTERED (confirm review M1): every reviewed pair under the bound, in ANY condition."""
    return [b for b in result['below'] if b['reviewed']]

cur = report('SHEET (the tokens as read from --repo)', palette({}))
new = report('PROPOSED', palette(PROPOSED))

print('\nSibling checks (the same ink-on-fill shape elsewhere on the chip and beside it):')
sib = {
    'state-ink-unconfirmed on scene-paper (hollow chip)': contrast(hex2rgb(TOK['state-ink-unconfirmed']), hex2rgb(TOK['scene-paper'])),
    'scene-unrecognised on scene-paper (unrecognised chip)': contrast(hex2rgb(TOK['scene-unrecognised']), hex2rgb(TOK['scene-paper'])),
    'scene-flag-ink on scene-flag (the flag)': contrast(hex2rgb(TOK['scene-flag-ink']), hex2rgb(TOK['scene-flag'])),
    'scene-ink on scene-badge (a badge)': contrast(hex2rgb(TOK['scene-ink']), hex2rgb(TOK['scene-badge'])),
    'scene-ink on scene-plate (the nameplate)': contrast(hex2rgb(TOK['scene-ink']), hex2rgb(TOK['scene-plate'])),
    'scene-ink on the plank (facts text over the floor)': contrast(hex2rgb(TOK['scene-ink']), hex2rgb(PLANK)),
}
for k, v in sib.items():
    print(f'  {v:5.2f}  {k}' + ('' if v >= 4.5 else '  <-- UNDER 4.5'))

if a.json:
    Path(a.json).write_text(json.dumps({'tokens_read': {k: v for k, v in TOK.items() if k.startswith('state-')}, 'model': {'lighting': LIGHTING, 'opacity': OPACITY, 'saturate': SATURATE, 'plank': PLANK, 'bound': BOUND},
                                        'current': cur, 'proposed': {**new, '_values': PROPOSED}, 'siblings': {k: round(v, 2) for k, v in sib.items()}}, indent=1))
    print('wrote', a.json)

if a.selftest:
    def clean(result):
        return reviewed_below(result) == []
    # Control 1: a fill under the floor is reported under it.
    assert contrast(hex2rgb('#6a7078'), ink) < 4.5, 'control 1: the planted fill was not under 4.5'
    # Control 2: a duplicate planted THROUGH report() within one lighting class — disabled := stale's token, both
    # dimmed — holds in EVERY condition and must be listed in all of them, as a reviewed pair.
    planted = report('PLANT', palette({'disabled': palette({})['stale']}), quiet=True)
    hits = [b for b in planted['below'] if b['pair'] == 'stale/disabled' and b['reviewed']]
    assert len(hits) == len(VISIONS) * len(CONDITIONS), f'control 2: listed {len(hits)} times, not {len(VISIONS) * len(CONDITIONS)}'
    assert not clean(planted), 'control 2: the acceptance stayed green on the duplicate'
    # Control 3 (M1): a TOKENS-ONLY breach — offline := disabled's token. As drawn they differ (dark vs dimmed),
    # so a check that read the drawn condition alone would stay green; the unfiltered acceptance must red.
    planted = report('PLANT', palette({**PROPOSED, 'offline': PROPOSED['disabled']}), quiet=True)   # on the PROPOSAL, so the two tokens are identical
    tok = [b for b in planted['below'] if b['pair'] == 'offline/disabled' and b['condition'] == 'tokens']
    drawn_ok = [b for b in planted['below'] if b['pair'] == 'offline/disabled' and b['condition'].startswith('drawn')]
    assert len(tok) == len(VISIONS), f'control 3: the tokens-only duplicate was listed {len(tok)} times in the tokens, not {len(VISIONS)}'
    assert drawn_ok == [], 'control 3: the plant is not tokens-only — it also breached as drawn, so it proves nothing about the filter'
    assert not clean(planted), 'control 3: the acceptance stayed green on a tokens-only breach'
    # Control 4: the as-drawn model is not the identity.
    assert new['fills']['stale']['drawn_upper'] != new['fills']['stale']['token'], 'control 4: as_drawn is the identity'
    # The proposal: every reviewed fill holds the ink at full light; no reviewed pair under the bound in ANY condition.
    assert all(new['fills'][s]['ink_contrast'] >= 4.5 for s in REVIEWED), 'proposal under 4.5 at full light'
    assert clean(new), f"proposal has reviewed pairs under {BOUND}: {[b for b in new['below'] if b['reviewed']]}"
    print(f'\nselftest: 4 controls red where planted; proposal clean at full light and in every condition (bound {BOUND})')

if a.check:
    bad = reviewed_below(cur)
    if bad:
        for b in bad:
            print(f"check: {b['pair']} {b['de']} under {BOUND} — {b['condition']}, {b['vision']}", file=sys.stderr)
        sys.exit(f'check: {len(bad)} reviewed pair(s) on the sheet under ΔE2000 {BOUND}')
    print(f'\ncheck: the sheet holds every pair involving {", ".join(REVIEWED)} at or above ΔE2000 {BOUND}, in every condition and vision')
