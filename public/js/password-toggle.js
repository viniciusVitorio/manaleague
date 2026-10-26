// "Olhinho" nos campos de senha: mostra/oculta o que foi digitado. Vale para todo input[type=password] da página.
(function () {
    'use strict';
    var NS = 'http://www.w3.org/2000/svg';
    // Ícones Lucide "eye" e "eye-off" (licença ISC).
    var ICONS = {
        show: ['M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0', 'circle'],
        hide: ['M10.73 5.08A10.43 10.43 0 0 1 12 5c4.18 0 7.92 2.6 9.94 6.65a1 1 0 0 1 0 .7 10.8 10.8 0 0 1-1.44 2.49',
            'M14.08 14.16a3 3 0 0 1-4.17-4.17', 'M17.48 17.5A10.75 10.75 0 0 1 2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 4.45-5.14', 'm2 2 20 20']
    };

    function icon(kind) {
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('width', '18'); svg.setAttribute('height', '18');
        svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
        ICONS[kind].forEach(function (d) {
            var el;
            if (d === 'circle') { el = document.createElementNS(NS, 'circle'); el.setAttribute('cx', '12'); el.setAttribute('cy', '12'); el.setAttribute('r', '3'); }
            else { el = document.createElementNS(NS, 'path'); el.setAttribute('d', d); }
            svg.appendChild(el);
        });
        return svg;
    }

    function enhance(input) {
        if (input.dataset.revealReady) return;
        input.dataset.revealReady = '1';
        var wrap = document.createElement('span');
        wrap.className = 'pw-field';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'pw-toggle';
        function sync() {
            var visible = input.type === 'text';
            button.replaceChildren(icon(visible ? 'hide' : 'show'));
            button.setAttribute('aria-label', visible ? 'Ocultar senha' : 'Mostrar senha');
            button.setAttribute('aria-pressed', visible ? 'true' : 'false');
            button.title = visible ? 'Ocultar senha' : 'Mostrar senha';
        }
        button.addEventListener('click', function () {
            var start = input.selectionStart, end = input.selectionEnd;
            input.type = input.type === 'password' ? 'text' : 'password';
            sync();
            input.focus();
            try { input.setSelectionRange(start, end); } catch (e) { /* alguns navegadores não deixam */ }
        });
        // Nunca enviar o formulário com a senha "à mostra" (evita o navegador salvar como texto comum).
        if (input.form) input.form.addEventListener('submit', function () { input.type = 'password'; });
        sync();
        wrap.appendChild(button);
    }

    function init() { document.querySelectorAll('input[type="password"]').forEach(enhance); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
