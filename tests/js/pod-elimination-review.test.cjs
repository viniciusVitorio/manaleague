const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = name => fs.readFileSync(path.join(__dirname, '../../public/js', name), 'utf8');

test('participant input minimum tracks Commander elimination only and resets for legacy modes', () => {
    // The form now uses accessible radio cards, not a select. Run the shipped
    // script through the shared stateful DOM adapter rather than the old shim.
    const dom = require('./helpers/tournament-form.cjs')({system: 'pod_elimination'});
    dom.run();
    assert.equal(dom.nodes['[name="max_players"]'].min, '3');
    assert.equal(dom.nodes['[data-player-limit-hint]'].textContent, 'Opcional, entre 3 e 128');
    dom.select('commander_pods');
    dom.listeners['manaleague:updated']();
    assert.equal(dom.nodes['[name="max_players"]'].min, '4');
    dom.select('pod_elimination');
    dom.formatNode.value = 'Modern';
    dom.listeners['manaleague:updated']();
    assert.equal(dom.nodes['[name="max_players"]'].min, '4');
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
