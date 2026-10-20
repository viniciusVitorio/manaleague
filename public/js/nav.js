// Menu hamburger do topo (telas pequenas).
// Abre/fecha pelo botao, fecha com Esc, clique fora ou ao voltar para tela larga.
(function () {
    'use strict';

    function init() {
        var toggle = document.querySelector('.nav-toggle');
        var menu = document.getElementById('main-menu');
        if (!toggle || !menu) return;

        function setOpen(open) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
            menu.classList.toggle('is-open', open);
        }

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            setOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });

        document.addEventListener('click', function (event) {
            if (!menu.contains(event.target)) setOpen(false);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menu.classList.contains('is-open')) {
                setOpen(false);
                toggle.focus();
            }
        });

        window.matchMedia('(min-width: 781px)').addEventListener('change', function (mq) {
            if (mq.matches) setOpen(false);
        });
    }

    // Paineis simples abertos por botao: <button data-toggle="id-do-painel">. O painel usa o atributo hidden.
    function initToggles() {
        document.querySelectorAll('[data-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var panel = document.getElementById(button.dataset.toggle);
                if (!panel) return;
                panel.hidden = !panel.hidden;
                document.querySelectorAll('[data-toggle="' + button.dataset.toggle + '"][aria-expanded]').forEach(function (b) {
                    b.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                });
                if (!panel.hidden) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        });
    }

    function boot() { init(); initToggles(); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
