/**
 * The Design-flow app's palette finish in the browser, so the server's
 * ImageMagick never touches the colors (ImageMagick 7.1.2 Q16-HDRI blackened
 * designs and gave shaded art wrong-colored patches).
 *
 * window.MGPalettePng.finish(rgba, width, height, colors = 255) -> Promise<{ png: Uint8Array, colors }>
 *
 * Follows design_flow/upscale/service.py _postprocess (smooth alpha, the
 * default): clean_transparent_edges (image_edges.py, NumPy path), alpha below
 * SMOOTH_ALPHA_CUTOFF (6) transparent, transparent RGB zeroed, then Pillow's
 * Image.quantize(colors, FASTOCTREE, dither NONE) on RGBA (libImaging/
 * QuantOctree.c and Quant.c, Pillow 12.3) and _zero_transparent_palette_entries.
 * The transparent margin is not cropped (the server checks the exact 2×/3×/4×
 * size; the plugin crops before the upscale). The PNG keeps only the palette
 * entries in use.
 *
 * Bucket ties sort stably, as glibc's qsort does; Pillow on Windows (MSVC
 * qsort) may order equally frequent buckets differently.
 */
(function () {
    'use strict';

    const EDGE_RADIUS = 3;
    const INTERIOR_SEED_ALPHA = 250;
    const SMOOTH_ALPHA_CUTOFF = 6;
    // QuantOctree.c CUBE_LEVELS_ALPHA: fine 8×16×8×8, coarse 4×4×4×4 buckets.
    const FINE_BITS = [3, 4, 3, 3];
    const COARSE_BITS = [2, 2, 2, 2];

    /** image_edges.clean_transparent_edges + service._prepare_smooth_alpha, in place. */
    function prepareSmoothAlpha(px, w, h) {
        const n = w * h;
        let partial = false;
        for (let p = 0; p < n; p++) {
            if (px[p * 4 + 3] < 255) {
                partial = true;
                break;
            }
        }
        if (partial) {
            for (let p = 0; p < n; p++) {
                if (px[p * 4 + 3] === 0) {
                    px[p * 4] = px[p * 4 + 1] = px[p * 4 + 2] = 0;
                }
            }
            propagateEdges(px, w, h);
        }
        for (let p = 0; p < n; p++) {
            if (px[p * 4 + 3] < SMOOTH_ALPHA_CUTOFF) {
                px[p * 4] = px[p * 4 + 1] = px[p * 4 + 2] = px[p * 4 + 3] = 0;
            }
        }
    }

    /**
     * _propagate_numpy_edges: for EDGE_RADIUS steps, partly transparent pixels
     * next to the frontier take the color of the seed (alpha >= 250) it came
     * from; within a step the first direction in this order wins.
     */
    function propagateEdges(px, w, h) {
        const n = w * h;
        const owner = new Int32Array(n).fill(-1);
        const visited = new Uint8Array(n);
        const directions = [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]];
        // Every seed is visited and owns itself; only those next to a pixel they
        // can claim (0 < alpha < 250) need to be in the first frontier.
        let frontier = [];
        for (let p = 0; p < n; p++) {
            if (px[p * 4 + 3] < INTERIOR_SEED_ALPHA) {
                continue;
            }
            owner[p] = p;
            visited[p] = 1;
            const y = p / w | 0, x = p % w;
            for (const [dy, dx] of directions) {
                const yy = y + dy, xx = x + dx;
                if (yy >= 0 && yy < h && xx >= 0 && xx < w) {
                    const a = px[(yy * w + xx) * 4 + 3];
                    if (a > 0 && a < INTERIOR_SEED_ALPHA) {
                        frontier.push(p);
                        break;
                    }
                }
            }
        }
        for (let step = 0; step < EDGE_RADIUS && frontier.length; step++) {
            const next = [];
            for (const [dy, dx] of directions) {
                // The target looks at target + delta: a frontier pixel f reaches f - delta.
                for (const f of frontier) {
                    const y = (f / w | 0) - dy, x = f % w - dx;
                    if (y < 0 || y >= h || x < 0 || x >= w) {
                        continue;
                    }
                    const t = y * w + x;
                    const a = px[t * 4 + 3];
                    if (visited[t] || a === 0 || a === 255) {
                        continue;
                    }
                    const s = owner[f];
                    px[t * 4] = px[s * 4];
                    px[t * 4 + 1] = px[s * 4 + 1];
                    px[t * 4 + 2] = px[s * 4 + 2];
                    owner[t] = s;
                    visited[t] = 1;
                    next.push(t);
                }
            }
            frontier = next;
        }
    }

    function newCube(bits) {
        const size = 1 << (bits[0] + bits[1] + bits[2] + bits[3]);
        return {
            bits: bits,
            offsets: [bits[1] + bits[2] + bits[3], bits[2] + bits[3], bits[3], 0],
            size: size,
            // Sums reach 255 × pixels: doubles stay exact far beyond any image here.
            count: new Float64Array(size), r: new Float64Array(size), g: new Float64Array(size),
            b: new Float64Array(size), a: new Float64Array(size),
        };
    }

    function posOf(cube, r, g, b, a) {
        return r << cube.offsets[0] | g << cube.offsets[1] | b << cube.offsets[2] | a << cube.offsets[3];
    }

    function offsetOf(cube, c) {
        const bits = cube.bits;
        return posOf(cube, c[0] >> (8 - bits[0]), c[1] >> (8 - bits[1]), c[2] >> (8 - bits[2]), c[3] >> (8 - bits[3]));
    }

    function addBucket(src, i, dst, j) {
        dst.count[j] += src.count[i];
        dst.r[j] += src.r[i];
        dst.g[j] += src.g[i];
        dst.b[j] += src.b[i];
        dst.a[j] += src.a[i];
    }

    /** copy_color_cube: expand or shrink to other bit depths. */
    function copyCube(cube, bits) {
        const result = newCube(bits);
        const srcReduce = [0, 0, 0, 0], dstReduce = [0, 0, 0, 0], width = [0, 0, 0, 0];
        for (let k = 0; k < 4; k++) {
            if (cube.bits[k] > bits[k]) {
                dstReduce[k] = cube.bits[k] - bits[k];
                width[k] = 1 << cube.bits[k];
            } else {
                srcReduce[k] = bits[k] - cube.bits[k];
                width[k] = 1 << bits[k];
            }
        }
        for (let r = 0; r < width[0]; r++) {
            for (let g = 0; g < width[1]; g++) {
                for (let b = 0; b < width[2]; b++) {
                    for (let a = 0; a < width[3]; a++) {
                        addBucket(cube, posOf(cube, r >> srcReduce[0], g >> srcReduce[1], b >> srcReduce[2], a >> srcReduce[3]),
                            result, posOf(result, r >> dstReduce[0], g >> dstReduce[1], b >> dstReduce[2], a >> dstReduce[3]));
                    }
                }
            }
        }
        return result;
    }

    function countUsed(cube) {
        let used = 0;
        for (let i = 0; i < cube.size; i++) {
            if (cube.count[i] > 0) {
                used++;
            }
        }
        return used;
    }

    /** A bucket as a plain record (create_sorted_color_palette copies them). */
    function bucketAt(cube, i) {
        return { count: cube.count[i], r: cube.r[i], g: cube.g[i], b: cube.b[i], a: cube.a[i] };
    }

    function clip8(v) {
        return v < 0 ? 0 : (v > 255 ? 255 : v);
    }

    /**
     * avg_color_from_color_bucket: C divides the uint64 sum by a float count in
     * single precision and truncates; Math.fround reproduces it.
     */
    function average(bucket) {
        if (bucket.count === 0) {
            return [0, 0, 0, 0];
        }
        const count = Math.fround(bucket.count);
        const avg = function (sum) { return clip8(Math.trunc(Math.fround(Math.fround(sum) / count))); };
        return [avg(bucket.r), avg(bucket.g), avg(bucket.b), avg(bucket.a)];
    }

    function sortedBuckets(cube) {
        const list = [];
        for (let i = 0; i < cube.size; i++) {
            list.push(bucketAt(cube, i));
        }
        // compare_bucket_count; Array.prototype.sort is stable.
        return list.sort(function (x, y) { return y.count - x.count; });
    }

    function subtractBuckets(cube, buckets, from, n) {
        for (let i = from; i < from + n; i++) {
            const s = buckets[i];
            if (s.count === 0) {
                continue;
            }
            const j = offsetOf(cube, average(s));
            cube.count[j] -= s.count;
            cube.r[j] -= s.r;
            cube.g[j] -= s.g;
            cube.b[j] -= s.b;
            cube.a[j] -= s.a;
        }
    }

    /** add_lookup_buckets: the lowest index wins a shared bucket. */
    function addLookups(cube, palette, n, offset) {
        for (let i = offset + n - 1; i >= offset; i--) {
            cube.count[offsetOf(cube, average(palette[i]))] = i;
        }
    }

    /**
     * quantize_octree with alpha: palette (RGBA records) and an index per pixel.
     * Transparent pixels already share one color (Quant.c sets them to the
     * first transparent pixel's, here always 0,0,0,0).
     */
    function quantizeOctree(px, n, nQuant) {
        const fine = newCube(FINE_BITS);
        const c = [0, 0, 0, 0];
        for (let p = 0; p < n; p++) {
            c[0] = px[p * 4]; c[1] = px[p * 4 + 1]; c[2] = px[p * 4 + 2]; c[3] = px[p * 4 + 3];
            const j = offsetOf(fine, c);
            fine.count[j] += 1;
            fine.r[j] += c[0];
            fine.g[j] += c[1];
            fine.b[j] += c[2];
            fine.a[j] += c[3];
        }
        const coarse = copyCube(fine, COARSE_BITS);
        let nCoarse = Math.min(countUsed(coarse), nQuant);
        let nFine = nQuant - nCoarse;
        const fineSorted = sortedBuckets(fine);
        subtractBuckets(coarse, fineSorted, 0, nFine);
        // Coarse buckets emptied by the subtraction make room for more fine colors.
        while (nCoarse > countUsed(coarse)) {
            const already = nFine;
            nCoarse = countUsed(coarse);
            nFine = nQuant - nCoarse;
            subtractBuckets(coarse, fineSorted, already, nFine - already);
        }
        const palette = sortedBuckets(coarse).slice(0, nCoarse).concat(fineSorted.slice(0, nFine));
        const coarseLookup = newCube(COARSE_BITS);
        addLookups(coarseLookup, palette, nCoarse, 0);
        const lookup = copyCube(coarseLookup, FINE_BITS);
        addLookups(lookup, palette, nFine, nCoarse);
        const indices = new Uint8Array(n);
        for (let p = 0; p < n; p++) {
            c[0] = px[p * 4]; c[1] = px[p * 4 + 1]; c[2] = px[p * 4 + 2]; c[3] = px[p * 4 + 3];
            indices[p] = lookup.count[offsetOf(lookup, c)];
        }
        return { palette: palette.map(average), indices: indices };
    }

    let crcTable = null;
    function crc32(bytes) {
        if (!crcTable) {
            crcTable = new Uint32Array(256);
            for (let i = 0; i < 256; i++) {
                let c = i;
                for (let k = 0; k < 8; k++) {
                    c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
                }
                crcTable[i] = c >>> 0;
            }
        }
        let c = 0xffffffff;
        for (let i = 0; i < bytes.length; i++) {
            c = crcTable[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
        }
        return (c ^ 0xffffffff) >>> 0;
    }

    function chunk(type, data) {
        const out = new Uint8Array(12 + data.length);
        const view = new DataView(out.buffer);
        view.setUint32(0, data.length);
        for (let i = 0; i < 4; i++) {
            out[4 + i] = type.charCodeAt(i);
        }
        out.set(data, 8);
        view.setUint32(8 + data.length, crc32(out.subarray(4, 8 + data.length)));
        return out;
    }

    async function zlib(bytes) {
        const stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate'));
        return new Uint8Array(await new Response(stream).arrayBuffer());
    }

    /** Indexed 8-bit PNG: PLTE, tRNS without the trailing opaque entries, unfiltered rows. */
    async function encodePng(indices, palette, w, h) {
        const ihdr = new Uint8Array(13);
        const view = new DataView(ihdr.buffer);
        view.setUint32(0, w);
        view.setUint32(4, h);
        ihdr[8] = 8;
        ihdr[9] = 3;
        const plte = new Uint8Array(palette.length * 3);
        let trnsLength = 0;
        const trns = new Uint8Array(palette.length);
        palette.forEach(function (c, i) {
            plte[i * 3] = c[0];
            plte[i * 3 + 1] = c[1];
            plte[i * 3 + 2] = c[2];
            trns[i] = c[3];
            if (c[3] !== 255) {
                trnsLength = i + 1;
            }
        });
        const rows = new Uint8Array((w + 1) * h);
        for (let y = 0; y < h; y++) {
            rows.set(indices.subarray(y * w, (y + 1) * w), y * (w + 1) + 1);
        }
        const parts = [
            new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
            chunk('IHDR', ihdr),
            chunk('PLTE', plte),
        ];
        if (trnsLength) {
            parts.push(chunk('tRNS', trns.subarray(0, trnsLength)));
        }
        parts.push(chunk('IDAT', await zlib(rows)), chunk('IEND', new Uint8Array(0)));
        const png = new Uint8Array(parts.reduce(function (s, p) { return s + p.length; }, 0));
        let at = 0;
        parts.forEach(function (p) { png.set(p, at); at += p.length; });
        return png;
    }

    async function finish(rgba, w, h, colors) {
        const px = new Uint8Array(rgba.buffer, rgba.byteOffset, w * h * 4).slice();
        prepareSmoothAlpha(px, w, h);
        const q = quantizeOctree(px, w * h, colors || 255);
        // _zero_transparent_palette_entries; then only the entries in use, in order.
        const used = new Uint8Array(256);
        for (let p = 0; p < q.indices.length; p++) {
            used[q.indices[p]] = 1;
        }
        const remap = new Uint8Array(256);
        const palette = [];
        q.palette.forEach(function (c, i) {
            if (used[i]) {
                remap[i] = palette.length;
                palette.push(c[3] === 0 ? [0, 0, 0, 0] : c);
            }
        });
        for (let p = 0; p < q.indices.length; p++) {
            q.indices[p] = remap[q.indices[p]];
        }
        return { png: await encodePng(q.indices, palette, w, h), colors: palette.length };
    }

    window.MGPalettePng = { finish: finish };
})();
