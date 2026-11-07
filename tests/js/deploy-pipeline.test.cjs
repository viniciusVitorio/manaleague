const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const workflow = fs.readFileSync(path.join(root, '.github/workflows/pipeline.yml'), 'utf8');
const waf = workflow.split('\n  waf:')[1].split('\n  deploy:')[0];
const deploy = workflow.split('\n  deploy:')[1];
const remote = deploy.split("<<'REMOTE'")[1].split('\n          REMOTE')[0];

test('app and reverb share the parametrized image with a local default', () => {
  const compose = fs.readFileSync(path.join(root, 'docker-compose.yml'), 'utf8');
  assert.equal(compose.match(/image: "\$\{APP_IMAGE:-manaleague-app:local\}"/g)?.length, 2);
});
test('WAF builds once, tests without rebuilding, then exports the tested commit image', () => {
  assert.match(waf, /APP_IMAGE: manaleague-app:\$\{\{ github.sha \}\}/);
  assert.match(waf, /docker compose build app\s+docker compose up -d --no-build --wait/);
  assert.match(waf, /bash docker\/deploy\/test-load-image.sh/);
  assert.match(waf, /node --test tests\/js\/deploy-pipeline.test.cjs/);
  assert.ok(waf.indexOf('docker save "$APP_IMAGE"') > waf.indexOf('Realtime (Reverb) behind Caddy'));
  assert.match(waf, /docker save "\$APP_IMAGE" \| gzip -1 > "\$RUNNER_TEMP\/app-image\/app.tar.gz"/);
  assert.match(waf, /sha256sum --text app.tar.gz > app.tar.gz.sha256/);
  assert.match(waf, /uses: actions\/upload-artifact@v4[\s\S]*name: app-image-\$\{\{ github.sha \}\}[\s\S]*path: \$\{\{ runner.temp \}\}\/app-image\/[\s\S]*retention-days: 1[\s\S]*compression-level: 0[\s\S]*if-no-files-found: error/);
});
test('image export fails without a checksum when docker save fails', () => {
  const step = waf.match(/^      - name: Export tested app image\r?\n        run: \|\r?\n((?:          .*\r?\n)+)/m);
  assert.ok(step, 'export step must contain a literal run block');
  const run = step[1].replace(/^ {10}/gm, '');
  const result = spawnSync('bash', ['-e', '-s'], {
    encoding: 'utf8',
    input: `
set +o pipefail
RUNNER_TEMP="$(mktemp -d)"
APP_IMAGE=regression-test:unused
trap 'status=$?; if [ -e "$RUNNER_TEMP/app-image/app.tar.gz.sha256" ]; then echo checksum=present; else echo checksum=absent; fi; cd /; rm -rf "$RUNNER_TEMP"; exit "$status"' EXIT
docker() { return 42; }
${run}`,
  });
  assert.ifError(result.error);
  assert.equal(result.status, 42, `export must propagate docker failure: ${result.stdout}${result.stderr}`);
  assert.match(result.stdout, /checksum=absent/);
  assert.doesNotMatch(result.stdout, /checksum=present/);
});
test('deploy downloads this run artifact only on push and transfers both image files', () => {
  assert.match(deploy, /- name: Download tested app image\s+if: github.event_name == 'push'\s+uses: actions\/download-artifact@v4\s+with:\s+name: app-image-\$\{\{ github.sha \}\}\s+path: \$\{\{ runner.temp \}\}\/app-image/);
  assert.doesNotMatch(deploy, /run-id:|repository:|github-token:/);
  assert.match(deploy, /printf 'APP_IMAGE=manaleague-app:%s\\n' "\$GITHUB_SHA" >> deploy.env/);
  assert.match(deploy, /scp [^\n]*"\$RUNNER_TEMP\/app-image\/app.tar.gz" "\$RUNNER_TEMP\/app-image\/app.tar.gz.sha256"/);
});
test('remote verifies and imports before activation and cannot compile', () => {
  assert.doesNotMatch(remote, /docker (?:compose[^\n]*\sbuild\b|build\b)|--build\b/);
  const load = remote.indexOf('sudo bash "$release_dir/docker/deploy/load-image.sh" /tmp/app.tar.gz /tmp/app.tar.gz.sha256 "manaleague-app:$RELEASE"');
  assert.ok(load >= 0);
  assert.ok(remote.indexOf('ln -sfn') > load);
  assert.match(remote, /compose -p manaleague up -d --no-build/);
  assert.match(remote, /database-pre-postgres.sqlite/);
  assert.match(workflow, /name: Remove temporary SSH access\s+if: always\(\)/);
});
