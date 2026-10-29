(() => {
    'use strict';
    // Confirmacoes e auto-submit sem handlers inline (paginas de partida ranqueada e moderacao).
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-confirm]');
        if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
    });
    // requestSubmit (e nao submit) para passar pelos formularios assincronos; delegado para sobreviver as atualizacoes.
    document.addEventListener('change', event => {
        const input = event.target.closest && event.target.closest('[data-autosubmit]');
        if (input && input.form) input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit();
    });
})();
