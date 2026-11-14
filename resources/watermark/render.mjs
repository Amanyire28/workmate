// Renders stamp.html to workmate-watermark.png (transparent, 4x) with a local Chrome or Edge.
// A tool for the people who maintain WorkMate; the server never runs it.
//   node resources/watermark/render.mjs "<path to chrome.exe / msedge.exe / google-chrome>"
import { spawn } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const browserPath = process.argv[2];
if (!browserPath) { console.error('Give the path to Chrome or Edge.'); process.exit(1); }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const port = 9400 + Math.floor(Math.random() * 400);
const browser = spawn(browserPath, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), 'wm-stamp-'))}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
let info;
for (let i = 0; i < 150 && !info; i++) { try { info = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); } catch { await sleep(200); } }
const ws = new WebSocket(info.webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let seq = 0; const waiting = new Map();
ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && waiting.has(m.id)) { const { ok, fail } = waiting.get(m.id); waiting.delete(m.id); m.error ? fail(new Error(m.error.message)) : ok(m.result); } });
const send = (method, params = {}, sessionId) => new Promise((ok, fail) => { const id = ++seq; waiting.set(id, { ok, fail }); ws.send(JSON.stringify({ id, method, params, sessionId })); });

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
const s = (m, p) => send(m, p, sessionId);
await s('Page.enable'); await s('Runtime.enable');
await s('Emulation.setDeviceMetricsOverride', { width: 800, height: 300, deviceScaleFactor: 4, mobile: false });
await s('Emulation.setDefaultBackgroundColorOverride', { color: { r: 0, g: 0, b: 0, a: 0 } });
await s('Page.navigate', { url: pathToFileURL(join(here, 'stamp.html')).href });
await sleep(1500);
const ready = await s('Runtime.evaluate', { expression: `document.fonts.ready.then(() => document.fonts.check('700 26px Inter') && document.fonts.check('600 16.5px Inter'))`, awaitPromise: true, returnByValue: true });
if (!ready.result.value) { console.error('Inter did not load (needs internet for Google Fonts).'); process.exit(1); }
const box = (await s('Runtime.evaluate', { expression: `JSON.stringify(document.getElementById('stamp').getBoundingClientRect())`, returnByValue: true })).result.value;
const r = JSON.parse(box);
const { data } = await s('Page.captureScreenshot', { format: 'png', clip: { x: r.x, y: r.y, width: Math.ceil(r.width), height: Math.ceil(r.height), scale: 1 } });
writeFileSync(join(here, 'workmate-watermark.png'), Buffer.from(data, 'base64'));
console.log(`workmate-watermark.png written (${Math.ceil(r.width) * 4} x ${Math.ceil(r.height) * 4})`);
ws.close(); browser.kill(); process.exit(0);
