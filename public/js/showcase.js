// Vitrine do perfil: [data-showcase-pick="N"] abre o seletor de deck para o espaço N.
(function () {
    'use strict';
    function init() {
        var dialog = document.getElementById('showcase-picker');
        if (!dialog || typeof dialog.showModal !== 'function') return;
        var form = dialog.querySelector('[data-showcase-form]');
        var search = dialog.querySelector('[data-showcase-search]');
        var empty = dialog.querySelector('.showcase-picker-empty');
        var title = dialog.querySelector('#showcase-picker-title');
        var baseAction = form.getAttribute('action');
        var opener = null;

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-showcase-pick]');
            if (!button) return;
            var slot = button.dataset.showcasePick;
            // A rota é /me/showcase/{slot}: troca o número do fim pelo espaço escolhido.
            form.setAttribute('action', baseAction.replace(/\/\d+$/, '/' + slot));
            title.textContent = 'Escolher deck para o espaço ' + slot;
            var menu = button.closest('.ui-menu');
            if (menu) menu.hidden = true;
            opener = button;
            if (search) { search.value = ''; filter(''); }
            dialog.showModal();
            if (search) search.focus();
        });

        dialog.querySelector('[data-showcase-close]').addEventListener('click', function () { dialog.close(); });
        // Clique no fundo escuro fecha.
        dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
        dialog.addEventListener('close', function () { if (opener && document.contains(opener)) opener.focus(); });

        function filter(q) {
            var shown = 0;
            dialog.querySelectorAll('.showcase-pick').forEach(function (row) {
                var match = !q || row.dataset.name.indexOf(q) !== -1;
                row.hidden = !match;
                if (match) shown++;
            });
            if (empty) empty.hidden = shown > 0;
        }
        if (search) search.addEventListener('input', function () { filter(search.value.trim().toLowerCase()); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
