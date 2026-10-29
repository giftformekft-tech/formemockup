/**
 * AI upscaling in the admin's browser, run by TensorFlow.js on WebGL. The
 * models and the library ship with the plugin, so no external service is called.
 *
 * window.MGAIUpscale.upscale(imageUrl, onProgress, factor, upscaler) -> Promise<Blob> (PNG)
 *
 * upscaler (default: the admin setting) picks the model:
 * - 'cugan', factor 3 or 2: Real-CUGAN up3x/up2x-conservative (the models-se
 *   weights of realcugan-ncnn-vulkan, MIT), the same models and tiling as the
 *   Design-flow app. Made for drawn art: flat areas stay flat, colors are kept
 *   without correction. Slower.
 * - 'esrgan', factor 4 or 2: Real-ESRGAN realesr-general-x4v3 (SRVGGNetCompact,
 *   BSD-3-Clause), a small plain network, several times less work per pixel;
 *   2× is its 4× averaged down. Its color correction is low-passed, so it does
 *   not put back the pixel grain of the generated image.
 *
 * Transparent designs: colors are bled into the fully transparent area first
 * (no dark fringes), the alpha channel is enlarged bicubically, as
 * realcugan-ncnn-vulkan does. With the palette setting on, the result is the
 * Design-flow 255-color palette PNG (assets/js/palette-png.js).
 */
(function () {
    'use strict';

    const TILE = 96;
    const ESRGAN_TILE = 128;
    // Context around a Real-ESRGAN tile (32 3×3 convolutions see 16 px; 10 px was seamless).
    const ESRGAN_PAD = 10;
    let esrganPromise = null;
    // Squeeze-excitation statistics for the whole image: every third 32 px
    // tile with its own means, averaged (realcugan-ncnn-vulkan's default
    // "very rough" sync), so the tiles do not differ in tone.
    const GAP_TILE = 32;
    const GAP_BATCH = 8;
    let tfPromise = null;
    const modelPromises = {};

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
                // Free each texture on dispose: TF.js otherwise pools every one it
                // used (about 2.6 GB for Real-CUGAN), the browser loses the WebGL
                // context on a laptop GPU, and every later read comes back zero.
                tf.env().set('WEBGL_DELETE_TEXTURE_THRESHOLD', 0);
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

    // model.json: the layer graph from tools/convert-realcugan.py; weights.bin: float32.
    function ensureModel(tf, factor) {
        const base = (config().models || {})[factor];
        if (!base) {
            return Promise.reject(new Error('Nincs ' + factor + '×-os felskálázó modell.'));
        }
        if (!modelPromises[factor]) {
            const promise = modelPromises[factor] = Promise.all([
                fetch(base + 'model.json').then(function (r) { if (!r.ok) { throw new Error('A modell leírója nem tölthető be.'); } return r.json(); }),
                fetch(base + 'weights.bin').then(function (r) { if (!r.ok) { throw new Error('A modell súlyai nem tölthetők be.'); } return r.arrayBuffer(); }),
            ]).then(function (res) {
                const model = res[0];
                const floats = new Float32Array(res[1]);
                const tensor = function (t) {
                    const size = t.shape.reduce(function (a, b) { return a * b; }, 1);
                    return tf.tensor(floats.subarray(t.offset, t.offset + size), t.shape);
                };
                model.layers.forEach(function (l) {
                    if (l.w) {
                        l.w = tensor(l.w);
                    }
                    if (l.b) {
                        l.b = tensor(l.b);
                    }
                });
                return model;
            });
            promise.catch(function () { delete modelPromises[factor]; });
        }
        return modelPromises[factor];
    }

    // Real-ESRGAN layer list: conv kernels [kh, kw, in, out], biases, PReLU alphas.
    function ensureEsrgan(tf, factor) {
        const base = (config().models || {}).esrgan;
        if (!base) {
            return Promise.reject(new Error('Nincs Real-ESRGAN felskálázó modell.'));
        }
        if (!esrganPromise) {
            esrganPromise = Promise.all([
                fetch(base + 'model.json').then(function (r) { if (!r.ok) { throw new Error('A modell leírója nem tölthető be.'); } return r.json(); }),
                fetch(base + 'weights.bin').then(function (r) { if (!r.ok) { throw new Error('A modell súlyai nem tölthetők be.'); } return r.arrayBuffer(); }),
            ]).then(function (res) {
                const floats = new Float32Array(res[1]);
                return res[0].layers.map(function (l) {
                    const size = l.shape.reduce(function (a, b) { return a * b; }, 1);
                    return tf.tensor(floats.subarray(l.offset, l.offset + size), l.shape);
                });
            });
            esrganPromise.catch(function () { esrganPromise = null; });
        }
        return esrganPromise.then(function (weights) {
            return { esrgan: weights, scale: factor === 2 ? 2 : 4, prepadding: ESRGAN_PAD, align: 1 };
        });
    }

    /**
     * Real-ESRGAN on a padded tile x [1, th + 2 pad, tw + 2 pad, 3]: the tile's
     * pixels, model.scale times, 0-255.
     */
    function runEsrgan(tf, model, x, th, tw) {
        const W = model.esrgan;
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
        h = tf.depthToSpace(h, 4, 'NHWC');
        const size = [x.shape[1] * 4, x.shape[2] * 4];
        let out = tf.add(h, tf.image.resizeNearestNeighbor(x, size));
        // Color correction: the model alone darkens dark tones by ~5%, which shows in
        // print. Only the 5×5 average of the difference goes back, so the generated
        // image's pixel grain, which the model removed, does not return as blobs.
        const diff = tf.avgPool(tf.sub(x, tf.avgPool(out, 4, 4, 'valid')), 5, 1, 'same');
        out = tf.add(out, tf.image.resizeBilinear(diff, size, true));
        const pad = model.prepadding * 4;
        out = out.slice([0, pad, pad, 0], [1, th * 4, tw * 4, 3]);
        if (model.scale === 2) {
            out = tf.avgPool(out, 2, 2, 'valid');
        }
        return out.clipByValue(0, 1).mul(255);
    }

    function activate(tf, x, act) {
        if (!act) {
            return x;
        }
        if (act.type === 'leaky') {
            return tf.leakyRelu(x, act.slope);
        }
        return act.type === 'relu' ? tf.relu(x) : tf.sigmoid(x);
    }

    /**
     * One pass of the graph on x [n, h, w, 3]. gaps: fixed SE inputs by name
     * ([1, C] tensors), or null for the tile's own means. record: collects the
     * means at each SE input ([n, C]); the pass ends once all are recorded.
     */
    function runGraph(tf, model, x, gaps, record) {
        const blobs = { in0: x };
        const pools = model.layers.filter(function (l) { return l.type === 'Pooling'; }).length;
        for (const l of model.layers) {
            const a = blobs[l.ins[0]];
            let y;
            if (l.type === 'Split') {
                l.outs.forEach(function (name) { blobs[name] = a; });
                continue;
            } else if (l.type === 'Convolution') {
                y = tf.conv2d(a, l.w, l.stride, 'valid');
            } else if (l.type === 'Deconvolution') {
                const n = a.shape[0], h = (a.shape[1] - 1) * l.stride + l.kernel, w = (a.shape[2] - 1) * l.stride + l.kernel;
                y = tf.conv2dTranspose(a, l.w, [n, h, w, l.out], l.stride, 'valid');
                if (l.pad) {
                    y = y.slice([0, l.pad, l.pad, 0], [n, h - 2 * l.pad, w - 2 * l.pad, l.out]);
                }
            } else if (l.type === 'Pooling') {
                const mean = tf.mean(a, [1, 2]);
                if (record) {
                    record[l.outs[0]] = mean;
                }
                y = gaps ? gaps[l.outs[0]] : mean;
            } else if (l.type === 'InnerProduct') {
                y = tf.matMul(a, l.w);
            } else if (l.type === 'BinaryOp') {
                const b = blobs[l.ins[1]];
                y = l.op === 'mul' ? tf.mul(a, b.reshape([b.shape[0], 1, 1, b.shape[1]])) : tf.add(a, b);
            } else if (l.type === 'Crop') {
                const s = a.shape;
                y = a.slice([0, l.start[0], l.start[1], 0], [s[0], s[1] - l.start[0] + l.end[0], s[2] - l.start[1] + l.end[1], s[3]]);
            } else {
                throw new Error('Ismeretlen modellréteg: ' + l.type);
            }
            if (l.b) {
                y = tf.add(y, l.b);
            }
            blobs[l.outs[0]] = activate(tf, y, l.act);
            if (record && Object.keys(record).length === pools) {
                return null;
            }
        }
        return blobs.out0;
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

    // Mirror at the image border without repeating the edge (PyTorch "reflect").
    function reflect(i, n) {
        if (n === 1) {
            return 0;
        }
        const period = 2 * (n - 1);
        i = ((i % period) + period) % period;
        return i < n ? i : period - i;
    }

    /** The network input for a tile: its pixels, the model's context around them, the size alignment. */
    function tileInput(src, w, h, model, x0, y0, tw, th, dst, at) {
        const pad = model.prepadding;
        const iw = Math.ceil(tw / model.align) * model.align + 2 * pad;
        const ih = Math.ceil(th / model.align) * model.align + 2 * pad;
        let o = at || 0;
        for (let y = 0; y < ih; y++) {
            const row = reflect(y0 - pad + y, h) * w;
            for (let x = 0; x < iw; x++) {
                const s = (row + reflect(x0 - pad + x, w)) * 3;
                dst[o++] = src[s];
                dst[o++] = src[s + 1];
                dst[o++] = src[s + 2];
            }
        }
        return [ih, iw];
    }

    function inputSize(model, n) {
        return Math.ceil(n / model.align) * model.align + 2 * model.prepadding;
    }

    async function syncGaps(tf, gpu, model, src, w, h, onStep) {
        const pick = function (n) {
            const out = [];
            for (let i = 0; n >= 3 ? i + 2 < n : i < n; i += n >= 3 ? 3 : 1) {
                out.push(i);
            }
            return out;
        };
        const groups = {};
        pick(Math.ceil(h / GAP_TILE)).forEach(function (yi) {
            pick(Math.ceil(w / GAP_TILE)).forEach(function (xi) {
                const tw = Math.min(GAP_TILE, w - xi * GAP_TILE), th = Math.min(GAP_TILE, h - yi * GAP_TILE);
                (groups[tw + 'x' + th] = groups[tw + 'x' + th] || []).push([xi * GAP_TILE, yi * GAP_TILE, tw, th]);
            });
        });
        const sums = {};
        let count = 0;
        for (const key of Object.keys(groups)) {
            const tiles = groups[key];
            for (let i = 0; i < tiles.length; i += GAP_BATCH) {
                const batch = tiles.slice(i, i + GAP_BATCH);
                const ih = inputSize(model, batch[0][3]), iw = inputSize(model, batch[0][2]);
                const data = new Float32Array(batch.length * ih * iw * 3);
                batch.forEach(function (t, k) {
                    tileInput(src, w, h, model, t[0], t[1], t[2], t[3], data, k * ih * iw * 3);
                });
                const totals = tf.tidy(function () {
                    const record = {};
                    runGraph(tf, model, tf.tensor4d(data, [batch.length, ih, iw, 3]), null, record);
                    const out = {};
                    Object.keys(record).forEach(function (name) { out[name] = tf.sum(record[name], 0); });
                    return out;
                });
                for (const name of Object.keys(totals)) {
                    const v = await gpu.read(totals[name]);
                    totals[name].dispose();
                    const acc = sums[name] = sums[name] || new Float64Array(v.length);
                    for (let c = 0; c < v.length; c++) {
                        acc[c] += v[c];
                    }
                }
                count += batch.length;
                onStep(batch.length);
                await tf.nextFrame();
            }
        }
        const gaps = {};
        Object.keys(sums).forEach(function (name) {
            gaps[name] = tf.tensor2d(Float32Array.from(sums[name], function (v) { return v / count; }), [1, sums[name].length]);
        });
        return gaps;
    }

    // Keys cubic (a = -0.75, as ncnn's bicubic Interp).
    function cubic(x) {
        const a = -0.75;
        x = Math.abs(x);
        if (x <= 1) {
            return ((a + 2) * x - (a + 3)) * x * x + 1;
        }
        return x < 2 ? ((a * x - 5 * a) * x + 8 * a) * x - 4 * a : 0;
    }

    /** Bicubic enlargement of the alpha channel (RGBA bytes in, offset 3) into out. */
    function bicubicAlpha(px, w, h, scale, out) {
        const W = w * scale, H = h * scale;
        // Output pixel X maps to (X + 0.5) / scale - 0.5 in the input.
        const taps = [];
        for (let r = 0; r < scale; r++) {
            const f = (r + 0.5) / scale - 0.5, base = Math.floor(f), t = f - base;
            taps.push({ base: base, k: [cubic(1 + t), cubic(t), cubic(1 - t), cubic(2 - t)] });
        }
        const clamp = function (i, n) { return i < 0 ? 0 : (i >= n ? n - 1 : i); };
        const rows = new Float32Array(h * W);
        for (let y = 0; y < h; y++) {
            for (let X = 0; X < W; X++) {
                const i = Math.floor(X / scale), t = taps[X - i * scale];
                let v = 0;
                for (let k = 0; k < 4; k++) {
                    v += px[(y * w + clamp(i + t.base - 1 + k, w)) * 4 + 3] * t.k[k];
                }
                rows[y * W + X] = v;
            }
        }
        for (let Y = 0; Y < H; Y++) {
            const j = Math.floor(Y / scale), t = taps[Y - j * scale];
            const r0 = clamp(j + t.base - 1, h) * W, r1 = clamp(j + t.base, h) * W, r2 = clamp(j + t.base + 1, h) * W, r3 = clamp(j + t.base + 2, h) * W;
            for (let X = 0, o = Y * W * 4 + 3; X < W; X++, o += 4) {
                out[o] = rows[r0 + X] * t.k[0] + rows[r1 + X] * t.k[1] + rows[r2 + X] * t.k[2] + rows[r3 + X] * t.k[3];
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

    const GPU_FAILED = 'A videokártya menet közben hibás eredményt adott, ezért a felskálázás nem menthető.';

    /**
     * Reads tensors while watching the WebGL context: a lost context reads back
     * zeros without an error, or never finishes a read.
     */
    function gpuReader(tf) {
        const backend = tf.backend();
        const gl = backend && backend.gpgpu && backend.gpgpu.gl;
        const error = function () {
            return new Error(GPU_FAILED + ' (a böngésző elvesztette a WebGL-t – frissítsd az oldalt)');
        };
        let onLost = null;
        const lost = new Promise(function (resolve, reject) {
            onLost = function () { reject(error()); };
        });
        lost.catch(function () {});
        if (gl && gl.canvas) {
            gl.canvas.addEventListener('webglcontextlost', onLost);
        }
        const check = function () {
            if (gl && gl.isContextLost()) {
                throw error();
            }
        };
        return {
            read: async function (tensor) {
                check();
                const data = await Promise.race([tensor.data(), lost]);
                check();
                return data;
            },
            close: function () {
                if (gl && gl.canvas) {
                    gl.canvas.removeEventListener('webglcontextlost', onLost);
                }
            },
        };
    }

    /** Share of the visible pixels that are not black (RGBA bytes). */
    function colored(data) {
        let visible = 0, lit = 0;
        for (let i = 0; i < data.length; i += 4) {
            if (data[i + 3] > 127) {
                visible++;
                if (data[i] > 16 || data[i + 1] > 16 || data[i + 2] > 16) {
                    lit++;
                }
            }
        }
        return visible ? lit / visible : 0;
    }

    async function upscale(url, onProgress, factor, upscaler) {
        const tf = await ensureTf();
        const model = (upscaler || config().upscaler) === 'esrgan'
            ? await ensureEsrgan(tf, factor)
            : await ensureModel(tf, factor || 3);
        const tileSize = model.esrgan ? ESRGAN_TILE : TILE;
        const SCALE = model.scale;
        const img = await loadImage(url);
        const w = img.naturalWidth, h = img.naturalHeight;
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0);
        const px = ctx.getImageData(0, 0, w, h).data;
        const inputColored = colored(px);
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
        const src = new Float32Array(w * h * 3);
        for (let p = 0, j = 0; p < w * h; p++, j += 3) {
            src[j] = px[p * 4] / 255;
            src[j + 1] = px[p * 4 + 1] / 255;
            src[j + 2] = px[p * 4 + 2] / 255;
        }
        const ow = w * SCALE, oh = h * SCALE;
        const out = new Uint8ClampedArray(ow * oh * 4);

        const pad = model.prepadding;
        // Constant when every pixel of the tile and its context is the same
        // (the model keeps a flat area flat), or when all of it is invisible.
        function constant(x0, y0, x1, y1) {
            x0 = Math.max(0, x0 - pad);
            y0 = Math.max(0, y0 - pad);
            x1 = Math.min(w, x1 + pad);
            y1 = Math.min(h, y1 + pad);
            const first = (y0 * w + x0) * 4;
            let same = true, invisible = transparent;
            for (let y = y0; y < y1 && (same || invisible); y++) {
                for (let x = x0, i = (y * w + x0) * 4; x < x1; x++, i += 4) {
                    if (px[i] !== px[first] || px[i + 1] !== px[first + 1] || px[i + 2] !== px[first + 2]) {
                        same = false;
                    }
                    if (px[i + 3] !== 0) {
                        invisible = false;
                    }
                }
            }
            return invisible ? [0, 0, 0] : (same ? [px[first], px[first + 1], px[first + 2]] : null);
        }

        const tiles = [];
        for (let y = 0; y < h; y += tileSize) {
            for (let x = 0; x < w; x += tileSize) {
                tiles.push([x, y, Math.min(tileSize, w - x), Math.min(tileSize, h - y)]);
            }
        }
        const work = tiles.filter(function (t) { return !constant(t[0], t[1], t[0] + t[2], t[1] + t[3]); });
        // One tile: its own statistics are exact. A gap sample costs about a fifth of a tile.
        const gapSamples = !model.esrgan && work.length > 1 ? Math.ceil(h / GAP_TILE / 3) * Math.ceil(w / GAP_TILE / 3) : 0;
        const total = work.length + gapSamples / 5 || 1;
        let done = 0;
        const step = function (units) {
            done += units;
            if (onProgress) {
                onProgress(Math.min(1, done / total));
            }
        };
        const gpu = gpuReader(tf);
        let gaps = null;
        try {
            gaps = gapSamples ? await syncGaps(tf, gpu, model, src, w, h, function (n) { step(n / 5); }) : null;
            for (const t of tiles) {
                const x = t[0], y = t[1], tw = t[2], th = t[3];
                const fill = constant(x, y, x + tw, y + th);
                let d = null, rowLen = 0;
                if (!fill) {
                    const ih = inputSize(model, th), iw = inputSize(model, tw);
                    const data = new Float32Array(ih * iw * 3);
                    tileInput(src, w, h, model, x, y, tw, th, data, 0);
                    const result = tf.tidy(function () {
                        const input = tf.tensor4d(data, [1, ih, iw, 3]);
                        return model.esrgan ? runEsrgan(tf, model, input, th, tw) : runGraph(tf, model, input, gaps, null).mul(255);
                    });
                    rowLen = result.shape[2];
                    d = await gpu.read(result);
                    result.dispose();
                    for (let i = 0; i < d.length; i++) {
                        if (d[i] !== d[i]) {
                            throw new Error(GPU_FAILED + ' (NaN)');
                        }
                    }
                }
                for (let yy = 0; yy < th * SCALE; yy++) {
                    let di = ((y * SCALE + yy) * ow + x * SCALE) * 4;
                    let si = yy * rowLen * 3;
                    for (let xx = 0; xx < tw * SCALE; xx++, di += 4, si += 3) {
                        if (fill) {
                            out[di] = fill[0];
                            out[di + 1] = fill[1];
                            out[di + 2] = fill[2];
                        } else {
                            out[di] = d[si];
                            out[di + 1] = d[si + 1];
                            out[di + 2] = d[si + 2];
                        }
                    }
                }
                if (!fill) {
                    step(1);
                    await tf.nextFrame();
                }
            }
        } finally {
            gpu.close();
            if (gaps) {
                Object.keys(gaps).forEach(function (name) { gaps[name].dispose(); });
            }
        }
        if (transparent) {
            bicubicAlpha(px, w, h, SCALE, out);
        } else {
            for (let i = 3; i < out.length; i += 4) {
                out[i] = 255;
            }
        }
        // Real-CUGAN keeps the colors; a black result is a failed GPU, not the design.
        if (inputColored > 0.05 && colored(out) < inputColored * 0.2) {
            throw new Error(GPU_FAILED + ' (fekete lett)');
        }
        if (onProgress) {
            onProgress(1);
        }
        if (config().palette && window.MGPalettePng) {
            // The Design-flow palette finish (assets/js/palette-png.js): Pillow's FASTOCTREE, 255 colors.
            const finished = await window.MGPalettePng.finish(out, ow, oh, 255);
            return new Blob([finished.png], { type: 'image/png' });
        }
        const result = document.createElement('canvas');
        result.width = ow;
        result.height = oh;
        result.getContext('2d').putImageData(new ImageData(out, ow, oh), 0, 0);
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

    window.MGAIUpscale = { upscale: upscale, factors: [2, 3, 4] };
})();
