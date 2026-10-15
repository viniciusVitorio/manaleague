// Construtor de deck: busca de cartas com autocomplete.
// Seguranca: todo conteudo vindo da API e inserido com textContent/atributos (nunca innerHTML),
// e o formulario envia apenas o ID da carta — o servidor busca os dados oficiais no Scryfall.
(function () {
    'use strict';

    function init() {
        var input = document.getElementById('card-search');
        if (!input) return;

        var results = document.getElementById('card-results');
        var status = document.getElementById('card-search-status');
        var form = document.getElementById('add-card-form');
        var isCommander = input.dataset.commander === '1';
        var timer = null;
        var controller = null;

        function setStatus(text) { status.textContent = text; }

        function button(label, board, card, primary) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-small ' + (primary ? 'btn-primary' : 'btn-ghost');
            b.textContent = label;
            b.addEventListener('click', function () {
                form.elements.scryfall_id.value = card.scryfall_id;
                form.elements.board.value = board;
                b.disabled = true;
                b.textContent = 'Adicionando…';
                form.submit();
            });
            return b;
        }

        function render(cards) {
            results.replaceChildren();
            if (!cards.length) { setStatus('Nenhuma carta encontrada.'); return; }
            setStatus(cards.length + (cards.length === 1 ? ' carta encontrada.' : ' cartas encontradas.'));

            cards.forEach(function (card) {
                var row = document.createElement('div');
                row.className = 'card-result';
                row.setAttribute('role', 'listitem');

                if (card.image_url) {
                    var img = document.createElement('img');
                    img.src = card.image_url;
                    img.alt = '';
                    img.loading = 'lazy';
                    img.width = 61; img.height = 85;
                    row.appendChild(img);
                }

                var info = document.createElement('div');
                info.className = 'card-result-info';
                var name = document.createElement('strong');
                name.textContent = card.printed_name && card.printed_name !== card.name ? card.printed_name + ' (' + card.name + ')' : card.name;
                var meta = document.createElement('small');
                meta.textContent = [card.type_line, card.mana_cost, card.set_code].filter(Boolean).join(' · ');
                info.appendChild(name);
                info.appendChild(meta);

                var actions = document.createElement('div');
                actions.className = 'card-result-actions';
                actions.appendChild(button('+ Deck', 'main', card, true));
                actions.appendChild(button('+ Side', 'side', card, false));
                if (isCommander && /Legendary/.test(card.type_line || '') && /(Creature|Planeswalker|Background)/.test(card.type_line || '')) {
                    actions.appendChild(button('Comandante', 'commander', card, false));
                }
                info.appendChild(actions);
                row.appendChild(info);
                results.appendChild(row);
            });
        }

        function search(term) {
            if (controller) controller.abort();
            controller = new AbortController();
            setStatus('Buscando…');

            fetch(input.dataset.endpoint + '?q=' + encodeURIComponent(term), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: controller.signal
            }).then(function (response) {
                if (response.status === 429) throw new Error('Muitas buscas seguidas. Aguarde alguns segundos.');
                if (!response.ok) throw new Error('Não foi possível buscar agora.');
                return response.json();
            }).then(function (payload) {
                render(payload.data || []);
            }).catch(function (error) {
                if (error.name !== 'AbortError') setStatus(error.message);
            });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var term = input.value.trim();
            if (term.length < 2) { results.replaceChildren(); setStatus('Digite ao menos 2 letras.'); return; }
            timer = setTimeout(function () { search(term); }, 350);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
