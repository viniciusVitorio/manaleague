import { cp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const publicRoot = resolve(root, 'public/vendor/tesseract');
const manifest = JSON.parse(await readFile(resolve(root, 'package.json'), 'utf8'));
const version = name => manifest.dependencies[name];
await rm(publicRoot, { recursive: true, force: true });
await mkdir(publicRoot, { recursive: true });
const tesseract = resolve(root, 'node_modules/tesseract.js');
const core = resolve(root, 'node_modules/tesseract.js-core');
await cp(resolve(tesseract, 'dist/worker.min.js'), resolve(publicRoot, 'worker.min.js'));
await cp(resolve(tesseract, 'dist/tesseract.min.js'), resolve(publicRoot, 'tesseract.min.js'));
await cp(resolve(tesseract, 'LICENSE.md'), resolve(publicRoot, 'TESSERACT-LICENSE.md'));
await cp(resolve(core, 'LICENSE'), resolve(publicRoot, 'CORE-LICENSE.txt'));
await cp(resolve(tesseract, 'dist/tesseract.min.js.LICENSE.txt'), resolve(publicRoot, 'BUNDLE-LICENSE.txt'));
for (const filename of await readdir(core)) {
  if (filename.startsWith('tesseract-core')) await cp(resolve(core, filename), resolve(publicRoot, filename));
}
for (const language of ['eng', 'por']) {
  await cp(resolve(root, `node_modules/@tesseract.js-data/${language}/4.0.0_best_int/${language}.traineddata.gz`), resolve(publicRoot, `${language}.traineddata.gz`));
}
await writeFile(resolve(publicRoot, 'versions.json'), JSON.stringify({ tesseract: version('tesseract.js'), core: version('tesseract.js-core'), languages: ['eng', 'por'] }, null, 2));
console.log(`Local OCR assets copied to public/vendor/tesseract (Tesseract.js ${version('tesseract.js')}, core ${version('tesseract.js-core')}, eng+por)`);
