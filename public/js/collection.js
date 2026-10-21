// Coleção: busca de carta por nome (Scryfall via /cards/search). Conteúdo da API só entra com textContent.
(function () {
    'use strict';
    function init() {
        var input = document.getElementById('collection-search');
        if (!input) return;
        var form = document.getElementById('collection-add');
        var list = document.getElementById('collection-results');
        var status = document.getElementById('collection-search-status');
        var picked = document.getElementById('collection-picked');
        var button = document.getElementById('collection-add-btn');
        var hidden = form.elements.scryfall_id;
        var manual = form.querySelector('[data-manual-id]');
        var timer = null, controller = null;

        function choose(card, row) {
            hidden.value = card.scryfall_id;
            picked.textContent = 'Selecionada: ' + card.name + (card.set_code ? ' (' + card.set_code + ')' : '');
            button.disabled = false;
            list.querySelectorAll('[aria-selected]').forEach(function (r) { r.setAttribute('aria-selected', 'false'); });
            if (row) row.setAttribute('aria-selected', 'true');
        }
        function render(cards) {
            list.replaceChildren();
            status.textContent = cards.length ? cards.length + ' resultado(s). Clique para escolher.' : 'Nenhuma carta encontrada.';
            cards.forEach(function (card) {
                var row = document.createElement('button');
                row.type = 'button'; row.className = 'pick-item'; row.setAttribute('role', 'option'); row.setAttribute('aria-selected', 'false');
                if (card.image_url) { var img = document.createElement('img'); img.src = card.image_url; img.alt = ''; img.loading = 'lazy'; img.width = 40; img.height = 56; row.appendChild(img); }
                var info = document.createElement('span');
                var n = document.createElement('strong'); n.textContent = card.name;
                var m = document.createElement('small'); m.textContent = [card.set_code, card.type_line, card.price_usd ? 'US$ ' + Number(card.price_usd).toFixed(2) : null].filter(Boolean).join(' · ');
                info.appendChild(n); info.appendChild(m); row.appendChild(info);
                row.addEventListener('click', function () { choose(card, row); });
                list.appendChild(row);
            });
        }
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) { list.replaceChildren(); status.textContent = ''; return; }
            timer = setTimeout(function () {
                if (controller) controller.abort();
                controller = new AbortController();
                status.textContent = 'Buscando…';
                fetch(input.dataset.endpoint + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: controller.signal })
                    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
                    .then(function (json) { render(json.data || []); })
                    .catch(function (e) { if (e.name !== 'AbortError') status.textContent = 'Scryfall indisponível. Tente de novo em instantes.'; });
            }, 300);
        });
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
        if (manual) manual.addEventListener('input', function () {
            var v = manual.value.trim();
            if (/^[0-9a-f-]{36}$/i.test(v)) { hidden.value = v; picked.textContent = 'ID informado manualmente.'; button.disabled = false; }
        });
        if (hidden.value) button.disabled = false;
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
