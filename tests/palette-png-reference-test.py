"""Compare assets/js/palette-png.js pixel by pixel with the Design-flow palette finish.

    python3 tests/palette-png-reference-test.py

Needs Node 18+, Pillow and NumPy. The reference is the Design-flow app's
_postprocess (smooth alpha): its clean_transparent_edges NumPy propagation and
_prepare_smooth_alpha written out here the same way, then Pillow's own
Image.quantize(255, FASTOCTREE, dither NONE) and _zero_transparent_palette_entries.
"""
import io
import json
import os
import subprocess
import sys
import tempfile

import numpy as np
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SEED_ALPHA = 250
CUTOFF = 6
DIRECTIONS = ((-1, -1), (-1, 0), (-1, 1), (0, -1), (0, 1), (1, -1), (1, 0), (1, 1))

NODE_RUNNER = r"""
const fs = require('fs');
global.window = {};
require(process.argv[2]);
(async () => {
    const jobs = JSON.parse(fs.readFileSync(process.argv[3], "utf8"));
    for (const job of jobs) {
        const raw = new Uint8Array(fs.readFileSync(job.raw));
        const t0 = Date.now();
        const result = await window.MGPalettePng.finish(raw, job.w, job.h, 255);
        fs.writeFileSync(job.png, result.png);
        job.colors = result.colors;
        job.ms = Date.now() - t0;
    }
    fs.writeFileSync(process.argv[3], JSON.stringify(jobs));
})().catch(e => { console.error(e); process.exit(1); });
"""


def shift_to_target(array, dy, dx, fill=0):
    h, w = array.shape[:2]
    out = np.full_like(array, fill)
    sy0, sy1, sx0, sx1 = max(dy, 0), min(h, h + dy), max(dx, 0), min(w, w + dx)
    ty0, ty1, tx0, tx1 = max(-dy, 0), min(h, h - dy), max(-dx, 0), min(w, w - dx)
    if sy0 < sy1 and sx0 < sx1:
        out[ty0:ty1, tx0:tx1] = array[sy0:sy1, sx0:sx1]
    return out


def clean_transparent_edges(pixels, radius=3):
    alpha = pixels[..., 3]
    if not np.any(alpha < 255):
        return pixels.copy()
    cleaned = pixels.copy()
    cleaned[alpha == 0, :3] = 0
    seeds = alpha >= SEED_ALPHA
    if not np.any(seeds):
        return cleaned
    h, w = alpha.shape
    semi = (alpha > 0) & (alpha < 255)
    owner = np.full((h, w), -1, dtype=np.int32)
    idx = np.flatnonzero(seeds)
    owner.ravel()[idx] = idx
    visited = seeds.copy()
    frontier = seeds.copy()
    source_rgb = cleaned.reshape(-1, 4)[:, :3]
    for _ in range(radius):
        if not np.any(frontier):
            break
        nxt = np.zeros_like(frontier)
        for dy, dx in DIRECTIONS:
            cand = shift_to_target(frontier, dy, dx) & semi & ~visited
            if not np.any(cand):
                continue
            src = shift_to_target(owner, dy, dx, fill=-1)[cand]
            cleaned[cand, :3] = source_rgb[src]
            owner[cand] = src
            visited[cand] = True
            nxt[cand] = True
        frontier = nxt
    return cleaned


def reference(pixels):
    cleaned = clean_transparent_edges(pixels)
    alpha = np.where(cleaned[..., 3] >= CUTOFF, cleaned[..., 3], 0).astype(np.uint8)
    cleaned[alpha == 0, :3] = 0
    cleaned[..., 3] = alpha
    q = Image.fromarray(cleaned, 'RGBA').quantize(colors=255, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE)
    raw = bytes(q.palette.palette)
    assert q.palette.mode == 'RGBA'
    pal = np.frombuffer(raw, dtype=np.uint8).reshape(-1, 4).copy()
    pal[pal[:, 3] == 0, :3] = 0
    return pal[np.array(q)]


def images():
    rng = np.random.default_rng(7)
    out = {}
    # Shaded skin with a soft edge, cheeks and the generated grain: the blotch case.
    h = w = 320
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float64)
    d = np.hypot(yy - 160, xx - 160) / 140
    skin = np.stack([235 - 60 * d ** 2, 190 - 80 * d ** 2, 160 - 90 * d ** 2], -1)
    cheek = np.exp(-((yy - 190) ** 2 + (xx - 100) ** 2) / 900)[..., None] * np.array([10, -30, -20])
    rgb = np.clip(skin + cheek + rng.normal(0, 1.5, (h, w, 1)), 0, 255)
    a = np.clip((1 - d) * 140, 0, 1) * 255
    img = np.dstack([rgb, a]).round().astype(np.uint8)
    img[a == 0, :3] = rng.integers(0, 255, (int((a == 0).sum()), 3))  # junk RGB under alpha 0
    out['skin'] = img
    # Every hue and lightness, opaque: more buckets than colors.
    hsv = np.dstack([np.tile(np.linspace(0, 255, 256), (256, 1)), np.full((256, 256), 200), np.tile(np.linspace(30, 255, 256)[:, None], (1, 256))])
    out['rainbow'] = np.array(Image.fromarray(hsv.astype(np.uint8), 'HSV').convert('RGBA'))
    # Random noise with random alpha: every cube level, coarse colors over the limit.
    out['noise'] = rng.integers(0, 256, (96, 96, 4), dtype=np.uint8)
    # A few-color logo with antialiased, contaminated edges and a faint haze.
    logo = Image.new('RGBA', (200, 120), (0, 0, 0, 0))
    from PIL import ImageDraw
    big = Image.new('RGBA', (800, 480), (0, 0, 0, 0))
    g = ImageDraw.Draw(big)
    g.ellipse((40, 40, 360, 440), fill=(232, 86, 42, 255), outline=(20, 20, 20, 255), width=24)
    g.rectangle((420, 120, 760, 360), fill=(29, 53, 87, 255))
    g.rectangle((0, 400, 800, 480), fill=(255, 255, 255, 12))
    logo = big.resize((200, 120), Image.LANCZOS)
    out['logo'] = np.array(logo)
    return out


def main():
    tmp = tempfile.mkdtemp(prefix='mg-palette-ref-')
    imgs = images()
    jobs = []
    for name, px in imgs.items():
        raw = os.path.join(tmp, name + '.rgba')
        px.tofile(raw)
        jobs.append({'name': name, 'raw': raw, 'png': os.path.join(tmp, name + '.png'), 'w': px.shape[1], 'h': px.shape[0]})
    jobs_path = os.path.join(tmp, 'jobs.json')
    with open(jobs_path, 'w') as f:
        json.dump(jobs, f)
    runner = os.path.join(tmp, 'run.js')
    with open(runner, 'w') as f:
        f.write(NODE_RUNNER)
    subprocess.run(['node', runner, os.path.join(ROOT, 'assets', 'js', 'palette-png.js'), jobs_path], check=True)
    with open(jobs_path) as f:
        jobs = json.load(f)
    failed = 0
    for job in jobs:
        with Image.open(job['png']) as im:
            im.load()
            mode = im.mode
            got = np.array(im.convert('RGBA'))
        want = reference(imgs[job['name']])
        same = np.array_equal(got, want)
        diff = int(np.abs(got.astype(int) - want.astype(int)).max())
        differing = int(np.any(got != want, axis=-1).sum())
        print(f"{job['name']}: {job['w']}x{job['h']} mode {mode}, {job['colors']} colors, {job['ms']} ms, "
              + ('identical to Design-flow' if same else f'{differing} pixels differ (max {diff})'))
        if mode != 'P' or not same or job['colors'] > 255:
            failed += 1
    if failed:
        print(f'FAIL: {failed} image(s) differ')
        sys.exit(1)
    print('Palette PNG reference: all images identical to the Design-flow palette finish.')


if __name__ == '__main__':
    main()
