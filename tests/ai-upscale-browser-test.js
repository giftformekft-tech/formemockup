// node tests/ai-upscale-browser-test.js
// Requires Playwright + Chromium. Runs the bundled Real-ESRGAN model through
// assets/js/ai-upscale.js on WebGL (SwiftShader in headless CI, so ~1 minute).
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.join(__dirname, '..');

async function main() {
    const server = http.createServer((req, res) => {
        const url = req.url.split('?')[0];
        if (url === '/') {
            res.writeHead(200, { 'content-type': 'text/html' });
            return res.end('<!doctype html><meta charset="utf-8"><body></body>');
        }
        const file = path.join(ROOT, url);
        if (!file.startsWith(ROOT) || !fs.existsSync(file)) {
            res.writeHead(404);
            return res.end();
        }
        res.writeHead(200, { 'content-type': url.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'application/octet-stream' });
        res.end(fs.readFileSync(file));
    });
    await new Promise(resolve => server.listen(0, resolve));
    const browser = await chromium.launch({
        headless: true,
        channel: process.env.MG_BROWSER_CHANNEL || undefined,
        args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
    });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`http://localhost:${server.address().port}/`);
        await page.evaluate(() => {
            window.MG_AI_UPSCALE = { tfUrl: '/assets/vendor/tfjs/tf.min.js?ver=4.22.0', modelUrl: '/assets/models/realesr-general-x4v3/' };
        });
        await page.addScriptTag({ url: '/assets/js/ai-upscale.js' });
        const result = await page.evaluate(async () => {
            async function run(draw, size) {
                const c = document.createElement('canvas');
                c.width = c.height = size;
                draw(c.getContext('2d'));
                const progress = [];
                const blob = await MGAIUpscale.upscale(c.toDataURL('image/png'), p => progress.push(p));
                const bmp = await createImageBitmap(blob);
                const o = document.createElement('canvas');
                o.width = bmp.width;
                o.height = bmp.height;
                const g = o.getContext('2d');
                g.drawImage(bmp, 0, 0);
                const d = g.getImageData(0, 0, o.width, o.height).data;
                let edge = 0, dark = 0;
                for (let i = 0; i < d.length; i += 4) {
                    if (d[i + 3] > 20 && d[i + 3] < 235) {
                        edge++;
                        if (d[i] < 150 && d[i + 1] < 60 && d[i + 2] < 60) dark++;
                    }
                }
                const at = (x, y) => Array.from(g.getImageData(x, y, 1, 1).data);
                return { type: blob.type, w: o.width, h: o.height, progress, edge, dark, at: { corner: at(2, 2), center: at(o.width / 2, o.height * 0.375) } };
            }
            const transparent = await run(g => {
                g.fillStyle = '#e8562a';
                g.beginPath();
                g.arc(48, 36, 26, 0, 7);
                g.fill();
                g.fillStyle = '#1d3557';
                g.font = 'bold 18px Arial';
                g.textAlign = 'center';
                g.fillText('APA', 48, 88);
            }, 96);
            const opaque = await run(g => {
                g.fillStyle = '#ffffff';
                g.fillRect(0, 0, 64, 64);
                g.fillStyle = '#222222';
                g.fillRect(16, 16, 32, 32);
            }, 64);
            return { transparent, opaque };
        });
        const t = result.transparent;
        assert.equal(t.type, 'image/png');
        assert.deepEqual([t.w, t.h], [384, 384], '4× size');
        assert.equal(t.at.corner[3], 0, 'transparent area stays transparent');
        assert.equal(t.at.center[3], 255, 'opaque fill stays opaque');
        [0xe8, 0x56, 0x2a].forEach((v, k) => assert.ok(Math.abs(t.at.center[k] - v) <= 6, 'fill color kept: ' + t.at.center));
        assert.ok(t.dark / Math.max(1, t.edge) < 0.02, 'no dark fringe on the transparent edge');
        assert.equal(t.progress[t.progress.length - 1], 1, 'progress reaches 100%');
        const o = result.opaque;
        assert.deepEqual([o.w, o.h], [256, 256]);
        assert.deepEqual(o.at.corner, [255, 255, 255, 255], 'white background unchanged');
        assert.ok(o.at.center[0] < 50 && o.at.center[3] === 255, 'dark square kept');
        assert.deepEqual(errors, []);
        console.log('AI upscale browser test passed (Real-ESRGAN 4×, colors, alpha).');
    } finally {
        await browser.close();
        server.close();
    }
}
main().catch(error => { console.error(error); process.exit(1); });
