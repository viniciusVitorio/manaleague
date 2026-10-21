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

    // Menus do cabecalho (Mais, conta, menu mobile): <button data-menu-button aria-controls="id">.
    // Um aberto por vez; fecha com Esc (devolvendo o foco), clique fora ou ao navegar.
    function initMenus() {
        var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-menu-button]'));
        if (!buttons.length) return;

        function panelOf(button) { return document.getElementById(button.getAttribute('aria-controls')); }
        function close(button) {
            var panel = panelOf(button);
            if (!panel || panel.hidden) return;
            panel.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            if (button.classList.contains('site-menu-toggle')) button.setAttribute('aria-label', 'Abrir menu');
        }
        function closeAll(except) { buttons.forEach(function (b) { if (b !== except) close(b); }); }
        function items(panel) { return Array.prototype.slice.call(panel.querySelectorAll('a, button')); }

        buttons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                var panel = panelOf(button);
                if (!panel) return;
                var open = panel.hidden;
                closeAll(button);
                panel.hidden = !open;
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (button.classList.contains('site-menu-toggle')) button.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
            });
            var panel = panelOf(button);
            if (!panel) return;
            button.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowDown') return;
                event.preventDefault();
                if (panel.hidden) button.click();
                var first = items(panel)[0];
                if (first) first.focus();
            });
            panel.addEventListener('focusout', function (event) {
                if (!panel.hidden && event.relatedTarget && !panel.contains(event.relatedTarget) && event.relatedTarget !== button) close(button);
            });
            panel.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
                var list = items(panel), index = list.indexOf(document.activeElement);
                event.preventDefault();
                var next = event.key === 'ArrowDown' ? index + 1 : index - 1;
                list[(next + list.length) % list.length].focus();
            });
        });

        document.addEventListener('click', function (event) {
            buttons.forEach(function (button) {
                var panel = panelOf(button);
                if (panel && !panel.contains(event.target)) close(button);
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            buttons.forEach(function (button) {
                var panel = panelOf(button);
                if (panel && !panel.hidden) { close(button); button.focus(); }
            });
        });
        window.matchMedia('(min-width: 961px)').addEventListener('change', function () { closeAll(null); });
    }

    // Switches "ligado = recebe": desligar habilita o hidden que envia a categoria como silenciada.
    function initMuteSwitches() {
        document.querySelectorAll('[data-mute]').forEach(function (sw) {
            sw.addEventListener('change', function () {
                var field = document.querySelector('[data-mute-field="' + sw.dataset.mute + '"]');
                if (field) field.disabled = sw.checked;
            });
        });
    }

    // <form data-confirm="..."> pede confirmacao; [data-copy="#id"] copia o valor/texto do alvo.
    function initConfirmAndCopy() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (form.dataset && form.dataset.confirm && !window.confirm(form.dataset.confirm)) event.preventDefault();
        }, true);
        document.querySelectorAll('[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var target = document.querySelector(button.dataset.copy);
                if (!target) return;
                var text = 'value' in target ? target.value : target.textContent.trim();
                var done = function () { var old = button.textContent; button.textContent = 'Copiado!'; setTimeout(function () { button.textContent = old; }, 1500); };
                if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { target.select && target.select(); });
                else if (target.select) { target.select(); document.execCommand('copy'); done(); }
            });
        });
    }

    function boot() { init(); initToggles(); initMenus(); initMuteSwitches(); initConfirmAndCopy(); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
