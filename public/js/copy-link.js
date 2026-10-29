// Botão "Copiar link do perfil": copia o endereço e avisa; sem acesso à área de transferência, mostra o link para copiar à mão.
(function () {
    'use strict';
    var timer;
    // Delegado no document: continua valendo depois de um envio sem recarregar (js/async-forms.js).
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-copy-link]');
        if (!button) return;
        var status = button.parentElement.querySelector('[data-copy-status]');

        function say(message) {
            if (!status) return;
            status.textContent = message;
            clearTimeout(timer);
            timer = setTimeout(function () { status.textContent = ''; }, 2500);
        }

        var url = button.getAttribute('data-copy-link');
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(function () { say('Link copiado'); }, function () {
                window.prompt('Copie o link do perfil:', url);
            });
        } else {
            window.prompt('Copie o link do perfil:', url);
        }
    });
})();
