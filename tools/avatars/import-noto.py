#!/usr/bin/env python3
"""Maintainer tool: refresh the Noto Emoji body library in resources/avatars/.

Fetches the chosen emoji SVGs (Apache-2.0, https://github.com/googlefonts/noto-emoji/tree/main/2D/svg), strips them down to
plain shapes, namespaces their ids, fits each one into the avatar frame with the same margins (so every body keeps a consistent
distance to the edges), and measures it in a real browser: its visible bounds, whether there is room for a face, and the colour
behind the eyes (which the eyelids need). The result is plain data, so the application never needs this tool or the network.

    python3 tools/avatars/import-noto.py            # needs Python Playwright with a Chromium; PYTHONPATH/PLAYWRIGHT_BROWSERS_PATH as usual
"""
import json, os, re, sys, unicodedata, urllib.request, base64
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'resources' / 'avatars'
CACHE = Path(os.environ.get('NOTO_CACHE', '/tmp/noto-cache'))
BASE = 'https://raw.githubusercontent.com/googlefonts/noto-emoji/main/2D/svg/'
# Everything in these Unicode blocks that is an object, food, plant, place or symbol; the filters below drop whatever has
# no room for a face. Faces, people, hands, animals (they bring their own eyes) and anything weaponish are skipped by name.
RANGES = [(0x1f330, 0x1f37f), (0x1f380, 0x1f3ff), (0x1f490, 0x1f4ff), (0x1f500, 0x1f53d), (0x1f680, 0x1f6c5), (0x1f90c, 0x1f93a),
          (0x1f9c0, 0x1f9ff), (0x1fa70, 0x1faff), (0x2600, 0x26ff), (0x2700, 0x27bf), (0x1f300, 0x1f32f), (0x1f5a4, 0x1f5ff)]
SKIP = re.compile(r'FACE|PERSON|MAN|WOMAN|HAND|FINGER|BABY|FOOT|EYE|EAR|NOSE|MOUTH|TONGUE|HEART|KISS|BODY|SKULL|BONE|BOMB|KNIFE|DAGGER|GUN|PISTOL|SWORD|'
                  r'COFFIN|URN|FLAG|CROSS|STAR OF|OM SYMBOL|SIGN|ARROW|BUTTON|SQUARE|CIRCLE|TRIANGLE|DIAMOND|ZODIAC|CLOCK FACE|KEYCAP|MARK|SYMBOL|'
                  r'WHEELCHAIR|TOILET|WC|SMOKING|PILL|SYRINGE|CIGARETTE|ADULT|MAHJONG|PLAYING CARD|CHECKER|CHESS|ANT\b|BEE\b|BUG|SPIDER|SCORPION|'
                  r'SNAKE|FISH|WHALE|SHARK|OCTOPUS|SQUID|CRAB|SHRIMP|LOBSTER|DINOSAUR|DRAGON|HORSE|COW|PIG|DOG|CAT|MOUSE|RAT|RABBIT|BEAR|PANDA|MONKEY|'
                  r'LION|TIGER|ELEPHANT|CAMEL|BIRD|CHICK|DUCK|OWL|PENGUIN|TURKEY|ROOSTER|FROG|TURTLE|LIZARD|CROCODILE|SHEEP|GOAT|DEER|BUTTERFLY|SNAIL|WORM|'
                  r'BRIEFS|SHORTS|SWIMSUIT|BIKINI|BRA\\b|LIPS|BITING|BREAST|SANTA|FATHER|MOTHER|ELF|PRINCE|PRINCESS|JUGGLING|FENCER|LIFTER|TROLL|MOOSE|YIN YANG|FITZPATRICK|MODIFIER|ARIES|TAURUS|GEMINI|CANCER|LEO\\b|VIRGO|LIBRA|SCORPIUS|SCORPIO|SAGITTARIUS|CAPRICORN|AQUARIUS|PISCES|OPHIUCHUS|POO|FIST|POINTING|BIGFOOT|SPEAKING HEAD|SILHOUETTE|ANGER|FLY\\b|DHARMA|CRESCENT|BEER|WINE|CHAMPAGNE|SAKE|COCKTAIL|TROPICAL DRINK|CLINKING|BOTTLE WITH|TOOTH|BRAIN|LUNG|ORGAN|DNA|MICROBE|PETRI|BLOOD|CONDOM|BROOM|PLUNGER|HOLE|NAZAR|HAMSA|MIRROR BALL|WAVING|TOILET|ROLL OF')
CODEPOINTS = ['%x' % cp for lo, hi in RANGES for cp in range(lo, hi + 1)
              if unicodedata.name(chr(cp), None) and not SKIP.search(unicodedata.name(chr(cp)))]  # code points this Python has no name for are new enough to skip
MAX_BYTES = 20000
UNSAFE = re.compile(r'<(script|style|image|foreignObject|filter|a)\b|on[a-z]+\s*=|javascript:', re.I)


def clean(svg, cp):
    inner = re.search(r'<svg\b[^>]*>(.*)</svg>', svg, re.S).group(1)
    inner = re.sub(r'<!--.*?-->', '', inner, flags=re.S)
    inner = re.sub(r'\s+', ' ', inner).replace('> <', '><').strip()
    inner = inner.replace('xlink:href', 'href')
    ids = set(re.findall(r'\bid="([^"]+)"', inner))
    for i in sorted(ids, key=len, reverse=True):
        inner = inner.replace(f'id="{i}"', f'id="n{cp}-{i}"').replace(f'url(#{i})', f'url(#n{cp}-{i})').replace(f'href="#{i}"', f'href="#n{cp}-{i}"')
    inner = re.sub(r'(\d+\.\d{2})\d+', r'\1', inner)
    inner = re.sub(r'\s*(xml:space|enable-background)="[^"]*"', '', inner)
    return inner


def uri_of(svg):
    return 'data:image/svg+xml;base64,' + base64.b64encode(svg.encode()).decode()


def bounds(page, svg):
    """Visible bounds (alpha > 24) of a 128 x 128 emoji, in its own units."""
    return page.evaluate("""async (uri) => {
      const img = new Image(); img.src = uri; await img.decode();
      const c = document.createElement('canvas'); c.width = c.height = 512;
      const x = c.getContext('2d', {willReadFrequently: true}); x.drawImage(img, 0, 0, 512, 512);
      const d = x.getImageData(0, 0, 512, 512).data; let x0 = 512, x1 = 0, y0 = 512, y1 = 0;
      for (let i = 3, n = 0; i < d.length; i += 4, n++) if (d[i] > 24) { const px = n % 512, py = (n / 512) | 0; if (px < x0) x0 = px; if (px > x1) x1 = px; if (py < y0) y0 = py; if (py > y1) y1 = py; }
      return x1 < x0 ? null : [x0 / 4, (x1 + 1) / 4, y0 / 4, (y1 + 1) / 4];
    }""", uri_of(svg))


def sample(page, svg, spots):
    """Mean RGBA around each (x, y) spot of the 240 x 220 avatar frame."""
    return page.evaluate("""async ([uri, spots]) => {
      const img = new Image(); img.src = uri; await img.decode();
      const c = document.createElement('canvas'); c.width = 480; c.height = 440;
      const x = c.getContext('2d', {willReadFrequently: true}); x.drawImage(img, 0, 0, 480, 440);
      return spots.map(([gx, gy]) => { const d = x.getImageData(Math.round(gx * 2) - 3, Math.round(gy * 2) - 3, 7, 7).data; const m = [0, 0, 0, 0];
        for (let i = 0; i < d.length; i++) m[i % 4] += d[i] / 49; return m; });
    }""", [uri_of(svg), spots])


def main():
    OUT.joinpath('bodies').mkdir(parents=True, exist_ok=True)
    manifest = json.loads((OUT / 'avatars.json').read_text())
    manifest['bodies'] = {k: v for k, v in manifest['bodies'].items() if not k.startswith('noto-')}
    kept, rejected = [], []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        page.set_content('<body></body>')
        for cp in CODEPOINTS:
            cached = CACHE / f'{cp}.svg'
            if cached.exists():
                raw = cached.read_text()
            else:
                try:
                    raw = urllib.request.urlopen(BASE + f'emoji_u{cp}.svg', timeout=20).read().decode()
                except Exception:
                    continue  # most code points in these blocks have no Noto SVG
                CACHE.mkdir(parents=True, exist_ok=True)
                cached.write_text(raw)
            if UNSAFE.search(raw):
                rejected.append((cp, 'uses features we do not carry'))
                continue
            inner = clean(raw, cp)
            # 1. Visible bounds in the emoji's own 128 x 128 frame.
            try:
                box = bounds(page, f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="512" height="512">{inner}</svg>')
            except Exception:
                rejected.append((cp, 'does not render'))
                continue
            if not box:
                rejected.append((cp, 'empty'))
                continue
            x0, x1, y0, y1 = box
            bw, bh = x1 - x0, y1 - y0
            # 2. Fit into the avatar frame: the same margins for every body, sitting on the ground line.
            scale = min(196 / bw, 182 / bh)
            tx, ty = 120 - (x0 + bw / 2) * scale, 194 - y1 * scale
            wrapped = f'<g transform="translate({tx:.2f} {ty:.2f}) scale({scale:.4f})">{inner}</g>'
            width, height = bw * scale, bh * scale
            top = 194 - height
            face_w = max(56.0, min(110.0, min(width, height) * 0.56))
            ax, ay = 120.0, top + height * 0.47
            s_ = face_w / 100
            # 3. Is there body behind the face, and what colour is it?
            spots = sample(page, f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 220" width="480" height="440">{wrapped}</svg>',
                           [(ax + (87 - 120) * s_, ay + (80 - 95) * s_), (ax + (151 - 120) * s_, ay + (80 - 95) * s_), (ax, ay + 15 * s_), (ax, ay)])
            if min(spot[3] for spot in spots) < 230:
                rejected.append((cp, 'no room for a face'))
                continue
            eyes = spots[:2]
            if max(abs(eyes[0][k] - eyes[1][k]) for k in range(3)) > 70 or max(abs(eyes[0][k] - spots[3][k]) for k in range(3)) > 100:
                rejected.append((cp, 'too busy behind the eyes'))
                continue
            lid = '#%02x%02x%02x' % tuple(round(sum(spot[k] for spot in eyes) / 2) for k in range(3))
            fragment = wrapped + '\n<!--over-->\n'
            if len(fragment) > MAX_BYTES:
                rejected.append((cp, f'{len(fragment)} bytes'))
                continue
            slug = f'noto-{cp}'
            (OUT / 'bodies' / f'{slug}.svg').write_text(fragment)
            name = unicodedata.name(chr(int(cp, 16)), cp).lower()
            manifest['bodies'][slug] = {'label': name, 'shadow': round(width * 0.46, 1), 'face': {'x': round(ax, 1), 'y': round(ay, 1), 'w': round(face_w, 1)}, 'lid': lid,
                                         'source': 'Noto Emoji', 'license': 'Apache-2.0'}
            kept.append(cp)
        browser.close()
    (OUT / 'avatars.json').write_text(json.dumps(manifest, indent=1) + '\n')
    for stale in OUT.joinpath('bodies').glob('noto-*.svg'):
        if stale.stem[5:] not in kept:
            stale.unlink()
    print(f'kept {len(kept)}; rejected {len(rejected)}')
    if '-v' in sys.argv:
        for cp, why in rejected:
            print('  rejected', cp, why)


if __name__ == '__main__':
    sys.exit(main())
