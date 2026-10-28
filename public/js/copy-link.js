// Botão "Copiar link do perfil": copia o endereço e avisa; sem acesso à área de transferência, mostra o link para copiar à mão.
(function () {
    'use strict';
    document.querySelectorAll('[data-copy-link]').forEach(function (button) {
        var status = button.parentElement.querySelector('[data-copy-status]');
        var timer;

        function say(message) {
            if (!status) return;
            status.textContent = message;
            clearTimeout(timer);
            timer = setTimeout(function () { status.textContent = ''; }, 2500);
        }

        button.addEventListener('click', function () {
            var url = button.getAttribute('data-copy-link');
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(function () { say('Link copiado'); }, function () {
                    window.prompt('Copie o link do perfil:', url);
                });
            } else {
                window.prompt('Copie o link do perfil:', url);
            }
        });
    });
})();
