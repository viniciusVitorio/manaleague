(() => {
    'use strict';
    // Confirmacoes e auto-submit sem handlers inline.
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-confirm]');
        if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
    });
    document.querySelectorAll('[data-autosubmit]').forEach(input => input.addEventListener('change', () => input.form.submit()));

    const panel = document.getElementById('queue-panel');
    if (!panel || !['searching', 'proposal'].includes(panel.dataset.state)) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const title = panel.querySelector('[data-queue-title]');
    const detail = panel.querySelector('[data-queue-detail]');
    let lastState = panel.dataset.state, failures = 0;

    // Enquanto a pagina estiver aberta, ela confirma presenca e roda o pareamento.
    async function beat() {
        if (document.hidden) { setTimeout(beat, 15000); return; }
        try {
            const response = await fetch(panel.dataset.heartbeatUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } });
            if (!response.ok) throw new Error(String(response.status));
            const data = await response.json();
            failures = 0;
            if (data.state === 'matched' && data.match_url) { window.location.assign(data.match_url); return; }
            if (data.state !== lastState) { window.location.reload(); return; }
            if (data.state === 'searching') {
                title.textContent = 'Buscando';
                detail.textContent = `Esperando há ${data.waited_minutes} min · aceitando diferença de até ${data.window} ELO · ${data.queue_size} ${data.queue_size === 1 ? 'pessoa' : 'pessoas'} nesta fila (contando você).`;
            }
            if (data.state === 'idle' || data.state === 'expired') { window.location.reload(); return; }
        } catch {
            failures++;
            detail.textContent = 'Sem conexão. Tentando de novo; sua vaga expira se a página ficar offline por muito tempo.';
        }
        setTimeout(beat, Math.min(15000 * (failures + 1), 60000));
    }
    setTimeout(beat, 3000);
})();
