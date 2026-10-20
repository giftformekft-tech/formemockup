/**
 * 4× AI upscaling in the admin's browser with Real-ESRGAN (realesr-general-x4v3,
 * BSD-3-Clause), run by TensorFlow.js on WebGL. The model and the library ship
 * with the plugin, so no external service is called.
 *
 * window.MGAIUpscale.upscale(imageUrl, onProgress) -> Promise<Blob> (PNG)
 *
 * A back-projection step keeps the original colors. Transparent designs:
 * colors are bled into the fully transparent area first
 * (no dark fringes), then RGB and alpha are upscaled separately, as Real-ESRGAN
 * itself does.
 */
(function () {
    'use strict';

    const SCALE = 4;
    const TILE = 128;
    const PAD = 10;
    let tfPromise = null;
    let weightsPromise = null;

    function config() {
        return window.MG_AI_UPSCALE || {};
    }

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            const s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = function () { reject(new Error('A TensorFlow.js nem tölthető be.')); };
            document.head.appendChild(s);
        });
    }

    function ensureTf() {
        if (!tfPromise) {
            tfPromise = (window.tf ? Promise.resolve() : loadScript(config().tfUrl)).then(async function () {
                const tf = window.tf;
                if (!(await tf.setBackend('webgl'))) {
                    throw new Error('A böngésző nem támogatja a WebGL-t, ezért az AI felskálázás itt nem futtatható.');
                }
                await tf.ready();
                return tf;
            });
            tfPromise.catch(function () { tfPromise = null; });
        }
        return tfPromise;
    }

    function ensureWeights(tf) {
        if (!weightsPromise) {
            const base = config().modelUrl;
            weightsPromise = Promise.all([
                fetch(base + 'model.json').then(function (r) { if (!r.ok) { throw new Error('A modell leírója nem tölthető be.'); } return r.json(); }),
                fetch(base + 'weights.bin').then(function (r) { if (!r.ok) { throw new Error('A modell súlyai nem tölthetők be.'); } return r.arrayBuffer(); }),
            ]).then(function (res) {
                const floats = new Float32Array(res[1]);
                return res[0].layers.map(function (l) {
                    const size = l.shape.reduce(function (a, b) { return a * b; }, 1);
                    return tf.tensor(floats.subarray(l.offset, l.offset + size), l.shape);
                });
            });
            weightsPromise.catch(function () { weightsPromise = null; });
        }
        return weightsPromise;
    }

    // SRVGGNetCompact: conv + PReLU blocks, pixel shuffle, plus a nearest-neighbor skip.
    function runModel(tf, W, x) {
        return tf.tidy(function () {
            let h = x;
            let i = 0;
            while (i < W.length) {
                h = tf.add(tf.conv2d(h, W[i], 1, 'same'), W[i + 1]);
                i += 2;
                if (i < W.length && W[i].rank === 1) {
                    h = tf.prelu(h, W[i]);
                    i += 1;
                }
            }
            h = tf.depthToSpace(h, SCALE, 'NHWC');
            const size = [x.shape[1] * SCALE, x.shape[2] * SCALE];
            const out = tf.add(h, tf.image.resizeNearestNeighbor(x, size));
            // Back-projection: shrunk back, the result must give the input's colors
            // (the model alone darkens dark tones by ~5%, which shows in print).
            const diff = tf.sub(x, tf.avgPool(out, SCALE, SCALE, 'valid'));
            return tf.add(out, tf.image.resizeBilinear(diff, size, true));
        });
    }

    /** Fill RGB of fully transparent pixels from their visible neighbors. */
    function bleedColors(data, w, h) {
        let filled = new Uint8Array(w * h);
        let frontier = 0;
        for (let p = 0; p < w * h; p++) {
            if (data[p * 4 + 3] > 0) {
                filled[p] = 1;
            } else {
                frontier++;
            }
        }
        for (let pass = 0; pass < 24 && frontier > 0; pass++) {
            const next = filled.slice();
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    const p = y * w + x;
                    if (filled[p]) {
                        continue;
                    }
                    let r = 0, g = 0, b = 0, n = 0;
                    for (let dy = -1; dy <= 1; dy++) {
                        const yy = y + dy;
                        if (yy < 0 || yy >= h) {
                            continue;
                        }
                        for (let dx = -1; dx <= 1; dx++) {
                            const xx = x + dx;
                            const q = yy * w + xx;
                            if (xx < 0 || xx >= w || !filled[q]) {
                                continue;
                            }
                            r += data[q * 4];
                            g += data[q * 4 + 1];
                            b += data[q * 4 + 2];
                            n++;
                        }
                    }
                    if (n) {
                        data[p * 4] = r / n;
                        data[p * 4 + 1] = g / n;
                        data[p * 4 + 2] = b / n;
                        next[p] = 1;
                        frontier--;
                    }
                }
            }
            filled = next;
        }
    }

    /**
     * Upscale one 3-channel float image [h, w, 3] (0..1) tile by tile into out
     * (RGBA bytes). constantFill(x0, y0, x1, y1) may return the value of a tile
     * that needs no model run (empty or single-colored), which saves most of
     * the time on designs with large transparent areas.
     */
    async function upscalePlane(tf, W, src, w, h, out, channels, constantFill, done, total, onProgress) {
        const ow = w * SCALE;
        for (let y = 0; y < h; y += TILE) {
            for (let x = 0; x < w; x += TILE) {
                const x0 = Math.max(0, x - PAD), y0 = Math.max(0, y - PAD);
                const x1 = Math.min(w, x + TILE + PAD), y1 = Math.min(h, y + TILE + PAD);
                const cw = Math.min(TILE, w - x) * SCALE, ch = Math.min(TILE, h - y) * SCALE;
                const fill = constantFill(x0, y0, x1, y1);
                if (fill) {
                    for (let yy = 0; yy < ch; yy++) {
                        let di = (((y * SCALE) + yy) * ow + x * SCALE) * 4;
                        for (let xx = 0; xx < cw; xx++, di += 4) {
                            if (channels === 'rgb') {
                                out[di] = fill[0];
                                out[di + 1] = fill[1];
                                out[di + 2] = fill[2];
                            } else {
                                out[di + 3] = fill[0];
                            }
                        }
                    }
                    done.count++;
                    if (onProgress) {
                        onProgress(done.count / total);
                    }
                    continue;
                }
                const t = tf.tidy(function () {
                    const tile = src.slice([y0, x0, 0], [y1 - y0, x1 - x0, 3]).expandDims(0);
                    return runModel(tf, W, tile).squeeze().mul(255).clipByValue(0, 255);
                });
                const d = await t.data();
                t.dispose();
                const tw = (x1 - x0) * SCALE;
                const cx0 = (x - x0) * SCALE, cy0 = (y - y0) * SCALE;
                for (let yy = 0; yy < ch; yy++) {
                    let si = ((cy0 + yy) * tw + cx0) * 3;
                    let di = (((y * SCALE) + yy) * ow + x * SCALE) * 4;
                    for (let xx = 0; xx < cw; xx++, si += 3, di += 4) {
                        if (channels === 'rgb') {
                            out[di] = d[si];
                            out[di + 1] = d[si + 1];
                            out[di + 2] = d[si + 2];
                        } else {
                            out[di + 3] = (d[si] + d[si + 1] + d[si + 2]) / 3;
                        }
                    }
                }
                done.count++;
                if (onProgress) {
                    onProgress(done.count / total);
                }
                await tf.nextFrame();
            }
        }
    }

    function loadImage(url) {
        return new Promise(function (resolve, reject) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () { resolve(img); };
            img.onerror = function () { reject(new Error('Az AI minta képe nem tölthető be.')); };
            // Cache-bust http(s) URLs: the file may have just been replaced.
            img.src = /^https?:|^\//.test(url) ? url + (url.indexOf('?') === -1 ? '?' : '&') + 'mgup=' + Date.now() : url;
        });
    }

    async function upscale(url, onProgress) {
        const tf = await ensureTf();
        const W = await ensureWeights(tf);
        const img = await loadImage(url);
        const w = img.naturalWidth, h = img.naturalHeight;
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0);
        const px = ctx.getImageData(0, 0, w, h).data;
        let transparent = false;
        for (let i = 3; i < px.length; i += 4) {
            if (px[i] < 255) {
                transparent = true;
                break;
            }
        }
        if (transparent) {
            bleedColors(px, w, h);
        }
        const rgb = new Float32Array(w * h * 3);
        const alpha = transparent ? new Float32Array(w * h * 3) : null;
        for (let p = 0, j = 0; p < w * h; p++, j += 3) {
            rgb[j] = px[p * 4] / 255;
            rgb[j + 1] = px[p * 4 + 1] / 255;
            rgb[j + 2] = px[p * 4 + 2] / 255;
            if (alpha) {
                alpha[j] = alpha[j + 1] = alpha[j + 2] = px[p * 4 + 3] / 255;
            }
        }
        const out = new Uint8ClampedArray(w * SCALE * h * SCALE * 4);
        if (!alpha) {
            for (let i = 3; i < out.length; i += 4) {
                out[i] = 255;
            }
        }
        const tilesPerPlane = Math.ceil(w / TILE) * Math.ceil(h / TILE);
        const total = tilesPerPlane * (alpha ? 2 : 1);
        const done = { count: 0 };
        // A tile is constant when every pixel of it (with its padding) is the same.
        function uniform(x0, y0, x1, y1, offset, count) {
            const first = (y0 * w + x0) * 4 + offset;
            for (let yy = y0; yy < y1; yy++) {
                for (let xx = x0; xx < x1; xx++) {
                    const i = (yy * w + xx) * 4 + offset;
                    for (let k = 0; k < count; k++) {
                        if (px[i + k] !== px[first + k]) {
                            return null;
                        }
                    }
                }
            }
            return Array.from(px.subarray(first, first + count));
        }
        const rgbFill = function (x0, y0, x1, y1) {
            if (alpha && uniform(x0, y0, x1, y1, 3, 1)?.[0] === 0) {
                return [0, 0, 0]; // invisible anyway
            }
            return uniform(x0, y0, x1, y1, 0, 3);
        };
        const alphaFill = function (x0, y0, x1, y1) {
            return uniform(x0, y0, x1, y1, 3, 1);
        };
        const rgbTensor = tf.tensor3d(rgb, [h, w, 3]);
        try {
            await upscalePlane(tf, W, rgbTensor, w, h, out, 'rgb', rgbFill, done, total, onProgress);
        } finally {
            rgbTensor.dispose();
        }
        if (alpha) {
            const alphaTensor = tf.tensor3d(alpha, [h, w, 3]);
            try {
                await upscalePlane(tf, W, alphaTensor, w, h, out, 'alpha', alphaFill, done, total, onProgress);
            } finally {
                alphaTensor.dispose();
            }
        }
        const result = document.createElement('canvas');
        result.width = w * SCALE;
        result.height = h * SCALE;
        result.getContext('2d').putImageData(new ImageData(out, w * SCALE, h * SCALE), 0, 0);
        return new Promise(function (resolve, reject) {
            result.toBlob(function (blob) {
                if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('A felskálázott PNG nem állítható elő.'));
                }
            }, 'image/png');
        });
    }

    window.MGAIUpscale = { upscale: upscale, scale: SCALE };
})();
