// Botões [data-copy="id-do-campo"]: copiam o texto do campo para a área de transferência.
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var button = e.target.closest('[data-copy]');
        if (!button) return;
        var field = document.getElementById(button.dataset.copy);
        if (!field) return;
        var label = button.textContent;
        function done() { button.textContent = button.dataset.copied || 'Copiado!'; setTimeout(function () { button.textContent = label; }, 1500); }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done, function () { field.select(); });
        } else {
            field.select();
            try { if (document.execCommand('copy')) done(); } catch (err) { /* o texto fica selecionado para Ctrl+C */ }
        }
    });
})();
