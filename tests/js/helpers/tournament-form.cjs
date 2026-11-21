const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// A stateful DOM adapter: run the actual shipped JS, including delegated events.
// PHP rendering tests separately verify the matching radios/fieldsets in Blade.
module.exports = function setup({format = 'Commander', system = 'commander_pods', legacy = null} = {}) {
    const nodes = {};
    const field = (selector, values = {}) => nodes[selector] = {hidden: false, disabled: false, checked: false, value: '', dataset: {}, querySelectorAll: () => [], ...values};
    const formatNode = field('[name="format"]', {value: format});
    const modes = ['commander_pods', 'pod_elimination', 'round_robin', 'swiss', 'single_elimination', 'double_elimination'];
    if (legacy) modes.push(legacy);
    const choices = modes.map((value, index) => {
        const radio = {value, type: 'radio', name: 'system', checked: value === system && (index < 2 || !legacy), disabled: index < 2 ? format !== 'Commander' : format === 'Commander'};
        const choice = {dataset: {formatGroup: index < 2 || index === 6 ? 'commander' : 'duel'}, hidden: radio.disabled, querySelector: () => radio};
        if (index === 6) { choice.dataset.legacySystem = ''; radio.checked = system === legacy; radio.disabled = format !== 'Commander'; }
        radio.choice = choice;
        return choice;
    });
    const radios = choices.map(choice => choice.querySelector());
    for (const selector of ['[data-pod-elimination-settings]', '[data-pod-advanced]', '[data-round-settings]', '[data-ranked-setting]', '[data-multiplayer-notice]', '[data-player-limit-hint]', '[data-pod-example]', '[data-consolation-setting]', '[data-custom-rules]', '[data-rules-summary]', '[data-ban-hint]']) field(selector);
    field('[name="max_players"]', {value: '', min: '4'});
    field('[name="pod_qualifiers"]', {value: '2'});
    const ranked = field('[name="ranked"][type="checkbox"]', {checked: true});

    field('[name="pod_consolation"][type="checkbox"]');
    field('[name="rules"]:checked', {value: 'standard'});
    const customFields = [field('[name="house_rules"]', {value: 'Proxies'}), field('[name="allow_banned"][type="checkbox"]', {checked: true})];
    const consolationFields = [nodes['[name="pod_consolation"][type="checkbox"]']];
    nodes['[data-custom-rules]'].querySelectorAll = () => customFields;
    nodes['[data-consolation-setting]'].querySelectorAll = () => consolationFields;
    field('[data-rules-copy]', {dataset: {rulesCopy: JSON.stringify(Object.fromEntries(['Commander', 'Pauper', 'Draft', 'Modern', 'Standard', 'Outro'].map(format => [format, {summary: `${format} rules`, banHint: `${format} bans`}])) )}});
    const form = {
        dataset: {},
        querySelector(selector) { if (selector === '[name="system"]:checked') return radios.find(radio => radio.checked); if (selector === '[name="system"]') return radios[0]; return nodes[selector]; },
        querySelectorAll(selector) { if (selector === '[data-system-choice]') return choices; if (selector === '[name="system"]') return radios; return []; },
    };
    const listeners = {};
    const document = {querySelectorAll: () => [form], addEventListener: (name, fn) => listeners[name] = fn};
    const change = () => listeners.change({target: {matches: () => true, closest: () => form}});
    const run = () => vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../../public/js/pod-elimination-form.js'), 'utf8'), {document});
    const select = value => { radios.forEach(radio => radio.checked = !radio.disabled && radio.value === value); change(); };
    return {nodes, form, radios, choices, listeners, run, select, change, formatNode, selected: () => radios.find(radio => radio.checked && !radio.disabled)?.value};
};
