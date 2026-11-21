const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = name => fs.readFileSync(path.join(__dirname, '../../public/js', name), 'utf8');

test('participant input minimum tracks Commander elimination only and resets for legacy modes', () => {
    const option = {};
    const fields = {
        '[name="format"]': {value: 'Commander'},
        '[name="system"]': {value: 'pod_elimination', querySelector: () => option},
        '[data-pod-elimination-settings]': {}, '[data-legacy-competition-settings]': {},
        '[name="ranked"]': {}, '[name="max_players"]': {min: '4'},
        '[data-player-limit-hint]': {textContent: 'Opcional, entre 4 e 128'},
    };
    const listeners = {};
    const document = {querySelectorAll: () => [{querySelector: selector => fields[selector]}], addEventListener: (name, fn) => listeners[name] = fn};
    vm.runInNewContext(script('pod-elimination-form.js'), {document});
    assert.equal(fields['[name="max_players"]'].min, '3');
    assert.equal(fields['[data-player-limit-hint]'].textContent, 'Opcional, entre 3 e 128');
    fields['[name="system"]'].value = 'commander_pods';
    listeners['manaleague:updated']();
    assert.equal(fields['[name="max_players"]'].min, '4');
    fields['[name="system"]'].value = 'pod_elimination';
    fields['[name="format"]'].value = 'Modern';
    listeners['manaleague:updated']();
    assert.equal(fields['[name="max_players"]'].min, '4');
});

test('live polling renders distinct pod numbers and phase labels as text', async () => {
    const element = tag => ({tag, children: [], textContent: '', append(...items) { this.children.push(...items); }, replaceChildren(...items) { this.children = items; }});
    const status = element('p'), standings = element('ol'), matches = element('div');
    const board = {dataset: {liveUrl: '/live'}, querySelectorAll: () => [], querySelector: selector => ({'[data-live-status]': status, '[data-live-standings]': standings, '[data-live-matches]': matches})[selector]};
    const document = {hidden: false, querySelectorAll: selector => selector === '[data-live-url]' ? [board] : [], createElement: element};
    const data = {system: 'pod_elimination', standings: [], matches: [1, 2].map(n => ({pod_number: n, phase: 'Semifinais', confirmed: false, seats: [{name: '<script>private</script>', place: null, advancing: false}]}))};
    vm.runInNewContext(script('live.js'), {document, window: {}, AbortController, setTimeout: () => 1, clearTimeout() {}, fetch: async () => ({ok: true, status: 200, headers: {get: () => null}, json: async () => data})});
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(matches.children.map(card => card.children[0].textContent), ['Mesa 1 · Semifinais', 'Mesa 2 · Semifinais']);
    assert.equal(matches.children[0].children[2].children[0].textContent, '<script>private</script> · –');
});
