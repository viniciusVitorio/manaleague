(() => {
    'use strict';
    const show = (form, selector, visible) => {
        const group = form.querySelector(selector);
        group.hidden = !visible;
        group.disabled = !visible;
    };
    const update = () => document.querySelectorAll('[data-tournament-form]').forEach(form => {
        const commander = form.querySelector('[name="format"]').value === 'Commander';
        const choices = [...form.querySelectorAll('[data-system-choice]')];
        choices.forEach(choice => {
            const radio = choice.querySelector('[name="system"]');
            const available = (choice.dataset.formatGroup === 'commander') === commander;
            choice.hidden = !available;
            radio.disabled = !available;
            if (!available) radio.checked = false;
        });
        if (!form.querySelector('[name="system"]:checked')) {
            const fallback = commander ? 'pod_elimination' : 'round_robin';
            const radio = choices.map(choice => choice.querySelector('[name="system"]')).find(input => !input.disabled && input.value === fallback);
            radio.checked = true;
        }
        show(form, '[data-custom-rules]', form.querySelector('[name="rules"]:checked').value === 'custom');
        const copy = JSON.parse(form.querySelector('[data-rules-copy]').dataset.rulesCopy)[form.querySelector('[name="format"]').value];
        form.querySelector('[data-rules-summary]').textContent = copy.summary;
        const system = form.querySelector('[name="system"]:checked').value;
        const elimination = system === 'pod_elimination';
        const multiplayer = elimination || system === 'commander_pods';
        show(form, '[data-pod-elimination-settings]', elimination);
        show(form, '[data-pod-advanced]', elimination);
        show(form, '[data-round-settings]', ['swiss', 'commander_pods'].includes(system));
        form.querySelector('[data-multiplayer-notice]').hidden = !multiplayer;
        form.querySelector('[data-ranked-setting]').hidden = multiplayer;
        const ranked = form.querySelector('[name="ranked"][type="checkbox"]');
        ranked.disabled = multiplayer;
        if (multiplayer) ranked.checked = false;
        form.querySelector('[data-ban-hint]').textContent = copy.banHint + ((!ranked.disabled || ranked.checked) ? (copy.rankedBanHint || '') : '');
        const topTwo = form.querySelector('[name="pod_qualifiers"]').value === '2';
        const capacity = form.querySelector('[name="max_players"]').value;
        show(form, '[data-consolation-setting]', elimination && topTwo && (!capacity || capacity === '8'));
        form.querySelector('[data-pod-example]').textContent = topTwo
            ? 'Exemplo com 8: 2 mesas de 4 → top 2 de cada → final de 4.'
            : 'Exemplo com 9: 3 mesas de 3 → top 1 de cada → final de 3.';
        form.querySelector('[name="max_players"]').min = elimination ? '3' : '4';
        form.querySelector('[data-player-limit-hint]').textContent = `Opcional, entre ${elimination ? 3 : 4} e 128`;
    });
    document.addEventListener('change', event => { if (event.target.closest('[data-tournament-form]')) update(); });
    document.addEventListener('input', event => {
        if (event.target.matches('[name="max_players"]') && event.target.closest('[data-tournament-form]')) update();
    });
    document.addEventListener('manaleague:updated', update);
    update();
})();
