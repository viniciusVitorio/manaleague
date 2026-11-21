const test = require('node:test');
const assert = require('node:assert/strict');
const setup = require('./helpers/tournament-form.cjs');
test('ranked ban guidance follows legacy duel availability and disappears for normal Commander pods', () => {
    const dom = setup({system: 'swiss', legacy: 'swiss'});
    const copy = JSON.parse(dom.nodes['[data-rules-copy]'].dataset.rulesCopy);
    copy.Commander.rankedBanHint = ' Ranked forbids bans.';
    dom.nodes['[data-rules-copy]'].dataset.rulesCopy = JSON.stringify(copy);
    dom.nodes['[name="ranked"][type="checkbox"]'].checked = false;
    dom.run();
    assert.equal(dom.nodes['[data-ban-hint]'].textContent, 'Commander bans Ranked forbids bans.');
    dom.nodes['[name="ranked"][type="checkbox"]'].checked = true;
    dom.change();
    assert.equal(dom.nodes['[data-ban-hint]'].textContent, 'Commander bans Ranked forbids bans.');
    dom.select('pod_elimination');
    assert.equal(dom.nodes['[data-ban-hint]'].textContent, 'Commander bans');
    dom.listeners['manaleague:updated']();
    assert.equal(dom.nodes['[data-ban-hint]'].textContent, 'Commander bans');
    dom.select('swiss');
    assert.equal(dom.nodes['[data-ban-hint]'].textContent, 'Commander bans Ranked forbids bans.');
});

test('format switching selects a compatible system and never submits hidden modes', () => {
    const dom = setup({format: 'Modern', system: 'swiss'});
    dom.run();
    dom.formatNode.value = 'Commander';
    dom.change();
    assert.equal(dom.selected(), 'pod_elimination');
    assert.deepEqual(dom.radios.filter(radio => !radio.disabled).map(radio => radio.value), ['commander_pods', 'pod_elimination']);
    dom.select('commander_pods');
    dom.listeners['manaleague:updated']();
    assert.equal(dom.selected(), 'commander_pods');
    dom.formatNode.value = 'Draft';
    dom.change();
    assert.equal(dom.selected(), 'round_robin');
    assert.equal(dom.nodes['[data-pod-elimination-settings]'].disabled, true);
    assert.equal(dom.nodes['[data-pod-advanced]'].disabled, true);
    assert.equal(dom.nodes['[data-round-settings]'].disabled, true);
    assert.equal(dom.nodes['[data-ranked-setting]'].hidden, false);
});

test('an explicitly saved legacy Commander duel survives initialization and async refresh', () => {
    const dom = setup({system: 'swiss', legacy: 'swiss'});
    dom.run();
    dom.listeners['manaleague:updated']();
    assert.equal(dom.selected(), 'swiss');
    assert.equal(dom.nodes['[data-round-settings]'].disabled, false);
    assert.equal(dom.nodes['[data-ranked-setting]'].hidden, false);
    dom.select('pod_elimination');
    assert.equal(dom.selected(), 'pod_elimination');
    assert.equal(dom.nodes['[name="ranked"][type="checkbox"]'].checked, false);
});

test('house rules are progressive and rule explanations follow the selected format', () => {
    const dom = setup();
    dom.run();
    assert.equal(dom.nodes['[data-custom-rules]'].disabled, true);
    assert.equal(dom.nodes['[data-custom-rules]'].hidden, true);
    assert.equal(dom.nodes['[data-rules-summary]'].textContent, 'Commander rules');
    dom.nodes['[name="rules"]:checked'].value = 'custom';
    dom.change();
    assert.equal(dom.nodes['[data-custom-rules]'].disabled, false);
    assert.equal(dom.nodes['[name="house_rules"]'].value, 'Proxies');
    dom.formatNode.value = 'Pauper';
    dom.change();
    assert.equal(dom.nodes['[data-rules-summary]'].textContent, 'Pauper rules');
    assert.equal(dom.nodes['[data-ban-hint]'].textContent, 'Pauper bans');
    dom.nodes['[name="rules"]:checked'].value = 'standard';
    dom.change();
    assert.equal(dom.nodes['[data-custom-rules]'].disabled, true);
    assert.equal(dom.nodes['[name="house_rules"]'].value, 'Proxies');
});

test('consolation is submitted only for eight players and top two; examples track qualifiers', () => {
    const dom = setup({system: 'pod_elimination'});
    dom.run();
    assert.equal(dom.nodes['[data-consolation-setting]'].disabled, false);
    dom.nodes['[name="pod_consolation"][type="checkbox"]'].checked = true;
    dom.nodes['[name="max_players"]'].value = '16';
    dom.change();
    assert.equal(dom.nodes['[data-consolation-setting]'].disabled, true);
    assert.equal(dom.nodes['[data-consolation-setting]'].hidden, true);
    dom.nodes['[name="max_players"]'].value = '8';
    dom.change();
    assert.equal(dom.nodes['[data-consolation-setting]'].disabled, false);
    dom.nodes['[name="pod_qualifiers"]'].value = '1';
    dom.change();
    assert.equal(dom.nodes['[data-consolation-setting]'].disabled, true);
    assert.match(dom.nodes['[data-pod-example]'].textContent, /9.*3 mesas de 3.*top 1.*final de 3/);
});

test('editing the participant limit immediately updates consolation before a blur or submit', () => {
    const dom = setup({system: 'pod_elimination'});
    dom.run();
    assert.equal(typeof dom.listeners.input, 'function');
    dom.nodes['[name="max_players"]'].value = '16';
    dom.listeners.input({target: {matches: () => true, closest: () => dom.form}});
    assert.equal(dom.nodes['[data-consolation-setting]'].disabled, true);
});

test('both multiplayer systems hide and clear ranked and only points pods accept rounds', () => {
    const dom = setup();
    dom.run();
    assert.equal(dom.nodes['[name="ranked"][type="checkbox"]'].disabled, true);
    assert.equal(dom.nodes['[name="ranked"][type="checkbox"]'].checked, false);
    assert.equal(dom.nodes['[data-ranked-setting]'].hidden, true);
    assert.equal(dom.nodes['[data-round-settings]'].disabled, false);
    dom.select('pod_elimination');
    assert.equal(dom.nodes['[data-round-settings]'].disabled, true);
    assert.equal(dom.nodes['[data-round-settings]'].hidden, true);
    assert.equal(dom.nodes['[data-pod-elimination-settings]'].disabled, false);
    assert.equal(dom.nodes['[name="max_players"]'].min, '3');
});
