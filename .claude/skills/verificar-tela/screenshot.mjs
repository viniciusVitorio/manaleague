// Prints de telas do ManaLeague local (Docker em http://localhost:8080) em várias larguras e temas, via Chrome headless (CDP).
// Uso:
//   node screenshot.mjs --urls /me/profile,/challenges --widths 1280,390 --out <pasta> [--selector .rival-card] [--theme dark|light|both]
//                       [--email demo@manaleague.test --password manaleague123] [--chrome "C:/Program Files/Google/Chrome/Application/chrome.exe"]
// Sem --selector, fotografa a página inteira. Imprime no fim os erros de JavaScript da página (ou "SEM ERROS").
import { spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, i, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[i + 1]]] : pairs), []));
const base = args.base ?? 'http://localhost:8080';
// Aceita "me/profile" ou "/me/profile". No Git Bash do Windows, "/me/profile" chega como "C:/Program Files/Git/me/profile"
// e "/p/ajani" como "P:/ajani": desfaz essas conversões.
const urls = (args.urls ?? 'me/profile').split(',').map(u => u.trim()
  .replace(/^[A-Za-z]:\/Program Files\/Git(?=\/)/i, '')
  .replace(/^([A-Za-z]):\//, (_, drive) => `/${drive.toLowerCase()}/`)
  .replace(/^(?!\/)/, '/'));
const widths = (args.widths ?? '1280,390').split(',').map(Number);
const themes = args.theme === 'both' ? ['dark', 'light'] : [args.theme ?? 'dark'];
const out = args.out ?? join(tmpdir(), 'manaleague-prints');
const chromePath = args.chrome ?? process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 9300 + Math.floor(Math.random() * 500);
mkdirSync(out, { recursive: true });

const chrome = spawn(chromePath, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), 'cdp-'))}`, 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let target;
for (let i = 0; i < 50 && !target; i++) {
  try { target = (await (await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t => t.type === 'page'); } catch { await sleep(200); }
}
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise(r => ws.addEventListener('open', r, { once: true }));
let id = 0; const waiting = new Map(); const errors = [];
ws.addEventListener('message', e => {
  const m = JSON.parse(e.data);
  if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
  if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') errors.push(m.params.args.map(a => a.value).join(' '));
  if (m.id && waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); }
});
const send = (method, params = {}) => new Promise(r => { const n = ++id; waiting.set(n, r); ws.send(JSON.stringify({ id: n, method, params })); });
const evaluate = async expression => (await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.result?.value;
const go = async url => { await send('Page.navigate', { url }); await sleep(2000); };
const metrics = (width, height) => send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 600 });

await send('Page.enable'); await send('Runtime.enable');
await metrics(1280, 900);
await go(`${base}/login`);
await evaluate(`(() => { const f = document.querySelector('input[name=email]').form;
  f.email.value = ${JSON.stringify(args.email ?? 'demo@manaleague.test')}; f.password.value = ${JSON.stringify(args.password ?? 'manaleague123')}; f.submit(); })()`);
await sleep(2500);

for (const theme of themes) {
  for (const url of urls) {
    for (const width of widths) {
      await metrics(width, 900);
      await go(base + url);
      await evaluate(`document.documentElement.dataset.theme = ${JSON.stringify(theme)}`);
      await sleep(300);
      const name = `${url.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home'}-${width}-${theme}`;
      let clip;
      if (args.selector) {
        clip = await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(args.selector)}); if (!el) return null;
          const r = el.getBoundingClientRect(); return { x: Math.max(0, r.x - 6), y: r.y + scrollY - 6, width: r.width + 12, height: r.height + 12, scale: 1 }; })()`);
        if (!clip) { console.log(`${name}: seletor ${args.selector} não encontrado`); continue; }
      } else {
        const height = await evaluate('document.documentElement.scrollHeight');
        await metrics(width, Math.min(height, 6000));
        await sleep(300);
      }
      const shot = await send('Page.captureScreenshot', { format: 'png', ...(clip ? { clip, captureBeyondViewport: true } : {}) });
      const file = join(out, name + '.png');
      writeFileSync(file, Buffer.from(shot.result.data, 'base64'));
      console.log(file);
    }
  }
}
console.log(errors.length ? 'ERROS DE JS: ' + errors.join(' | ') : 'SEM ERROS');
ws.close(); chrome.kill(); process.exit(0);
