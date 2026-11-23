(() => {
    'use strict';
    // Formulário de evento: o tipo (Campeonato ou Torneio) mostra só os seus sistemas; Eliminação por Pods só no Commander.
    // Rodadas aparecem no Suíço e nos Pods Commander; as opções de pods, na Eliminação por Pods.
    const update = () => document.querySelectorAll('form:has([data-pod-elimination-settings])').forEach(form => {
        const format = form.querySelector('[name="format"]');
        const kind = form.querySelector('[name="kind"]:checked')?.value;
        const systems = [...form.querySelectorAll('[name="system"]')];
        systems.forEach(input => {
            const otherKind = Boolean(kind) && input.dataset.kind !== kind;
            input.disabled = otherKind || (input.hasAttribute('data-commander-only') && format.value !== 'Commander');
            input.closest('[data-system-choice]').hidden = otherKind;
        });
        // Sistema escolhido ficou indisponível (outro tipo ou formato): marca o primeiro do tipo atual.
        let checked = systems.find(input => input.checked && !input.disabled);
        if (!checked) {
            checked = systems.find(input => !input.disabled);
            if (checked) checked.checked = true;
        }
        const system = checked?.value;
        const pod = system === 'pod_elimination';
        form.querySelector('[name="max_players"]').min = pod ? '3' : '4';
        form.querySelector('[data-player-limit-hint]').textContent = `Opcional, entre ${pod ? 3 : 4} e 128`;
        form.querySelector('[data-pod-elimination-settings]').hidden = !pod;
        form.querySelector('[data-rounds-setting]').hidden = !['swiss', 'commander_pods'].includes(system);
        const ranked = form.querySelector('[name="ranked"]');
        ranked.disabled = pod; if (pod) ranked.checked = false;
    });
    document.addEventListener('change', event => { if (event.target.matches('[name="format"], [name="system"], [name="kind"]')) update(); });
    document.addEventListener('manaleague:updated', update);
    update();
})();
