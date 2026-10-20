(() => {
    'use strict';
    // Confirmacoes e auto-submit sem handlers inline (paginas de partida ranqueada e moderacao).
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-confirm]');
        if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
    });
    document.querySelectorAll('[data-autosubmit]').forEach(input => input.addEventListener('change', () => input.form.submit()));
})();
