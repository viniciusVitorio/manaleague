const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = name => fs.readFileSync(path.join(__dirname, '../../public/js', name), 'utf8');

// Formulário falso com os cartões de sistema (radio name="system", data-kind), como na tela.
const eventForm = ({format, system, kind}) => {
    let selected = system;
    const inputs = [['round_robin', 'championship'], ['swiss', 'championship'], ['commander_pods', 'championship'],
        ['single_elimination', 'tournament'], ['double_elimination', 'tournament'], ['pod_elimination', 'tournament']]
        .map(([value, group]) => {
            const card = {hidden: false};
            // Como um radio de verdade: marcar um desmarca os outros do grupo.
            return {value, dataset: {kind: group}, disabled: false, card,
                get checked() { return selected === value; }, set checked(on) { if (on) selected = value; },
                hasAttribute: name => name === 'data-commander-only' && value === 'pod_elimination', closest: () => card};
        });
    const fields = {
        '[name="format"]': {value: format}, '[name="kind"]:checked': kind ? {value: kind} : null,
        '[data-pod-elimination-settings]': {}, '[data-rounds-setting]': {},
        '[name="ranked"]': {}, '[name="max_players"]': {min: '4'},
        '[data-player-limit-hint]': {textContent: 'Opcional, entre 4 e 128'},
    };
    const form = {querySelector: selector => fields[selector], querySelectorAll: selector => selector === '[name="system"]' ? inputs : []};
    const listeners = {};
    const document = {querySelectorAll: () => [form], addEventListener: (name, fn) => listeners[name] = fn};
    vm.runInNewContext(script('pod-elimination-form.js'), {document});
    const pick = value => { selected = value; };
    const checked = () => inputs.find(input => input.checked)?.value;
    return {fields, inputs, pick, checked, update: () => listeners['manaleague:updated']()};
};

test('participant input minimum tracks Commander elimination only and resets for legacy modes', () => {
    const {fields, pick, update} = eventForm({format: 'Commander', system: 'pod_elimination', kind: 'tournament'});
    assert.equal(fields['[name="max_players"]'].min, '3');
    assert.equal(fields['[data-player-limit-hint]'].textContent, 'Opcional, entre 3 e 128');
    assert.equal(fields['[data-pod-elimination-settings]'].hidden, false);
    assert.equal(fields['[data-rounds-setting]'].hidden, true);
    assert.equal(fields['[name="ranked"]'].disabled, true);
    fields['[name="kind"]:checked'].value = 'championship';
    pick('commander_pods');
    update();
    assert.equal(fields['[name="max_players"]'].min, '4');
    assert.equal(fields['[data-rounds-setting]'].hidden, false);
    fields['[name="kind"]:checked'].value = 'tournament';
    pick('pod_elimination');
    fields['[name="format"]'].value = 'Modern';
    update();
    assert.equal(fields['[name="max_players"]'].min, '4');
});

test('event kind shows only its own systems and moves the choice into the kind', () => {
    // Torneio com um sistema de campeonato marcado: vai para o primeiro mata-mata disponível (Pods só no Commander).
    const {fields, inputs, checked, update} = eventForm({format: 'Pauper', system: 'round_robin', kind: 'tournament'});
    assert.deepEqual(inputs.map(input => input.card.hidden), [true, true, true, false, false, false]);
    assert.equal(checked(), 'single_elimination');
    assert.equal(inputs.at(-1).disabled, true);
    assert.equal(fields['[data-rounds-setting]'].hidden, true);
    // De volta a Campeonato: o mata-mata some e a escolha volta para a liga.
    fields['[name="kind"]:checked'].value = 'championship';
    update();
    assert.deepEqual(inputs.map(input => input.card.hidden), [false, false, false, true, true, true]);
    assert.equal(checked(), 'round_robin');
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
