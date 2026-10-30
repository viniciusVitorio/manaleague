// Botões de copiar: link do perfil (data-copy-link="url") e lista do deck (data-copy-target="#textarea").
// Copia e avisa; sem acesso à área de transferência, mostra o texto para copiar à mão.
(function () {
    'use strict';
    var timer;
    // Delegado no document: continua valendo depois de um envio sem recarregar (js/async-forms.js).
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-copy-link], [data-copy-target]');
        if (!button) return;
        var status = button.parentElement.querySelector('[data-copy-status]');
        var target = button.hasAttribute('data-copy-target') ? document.querySelector(button.getAttribute('data-copy-target')) : null;
        var done = button.getAttribute('data-copy-done') || 'Link copiado';

        function say(message) {
            if (!status) return;
            status.textContent = message;
            clearTimeout(timer);
            timer = setTimeout(function () { status.textContent = ''; }, 2500);
        }

        function manual(text) {
            if (target) { target.focus(); target.select(); say('Selecionado: use Ctrl+C'); }
            else window.prompt('Copie o link do perfil:', text);
        }

        var text = target ? target.value : button.getAttribute('data-copy-link');
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { say(done); }, function () { manual(text); });
        } else {
            manual(text);
        }
    });
})();
