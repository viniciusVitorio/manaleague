(() => {
    'use strict';
    const update = () => document.querySelectorAll('form:has([data-pod-elimination-settings])').forEach(form => {
        const format = form.querySelector('[name="format"]'), system = form.querySelector('[name="system"]');
        const option = system.querySelector('[data-commander-only]');
        option.disabled = format.value !== 'Commander'; option.hidden = option.disabled;
        if (option.disabled && system.value === 'pod_elimination') system.value = 'round_robin';
        const pod = system.value === 'pod_elimination';
        form.querySelector('[name="max_players"]').min = pod ? '3' : '4';
        form.querySelector('[data-player-limit-hint]').textContent = `Opcional, entre ${pod ? 3 : 4} e 128`;
        form.querySelector('[data-pod-elimination-settings]').hidden = !pod;
        form.querySelector('[data-legacy-competition-settings]').hidden = pod;
        const ranked = form.querySelector('[name="ranked"]');
        ranked.disabled = pod; if (pod) ranked.checked = false;
    });
    document.addEventListener('change', event => { if (event.target.matches('[name="format"], [name="system"]')) update(); });
    document.addEventListener('manaleague:updated', update);
    update();
})();
