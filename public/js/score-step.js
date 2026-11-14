// Mini contador do placar nos cartões de partida em andamento (matches/partials/score-board): os botões − e + mudam o
// campo de vitórias dentro dos limites dele. O campo continua digitável; sem JavaScript, só ele funciona.
// Dispara "input" no campo: assim a atualização ao vivo sabe que há placar sendo preenchido e não troca a tela.
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-score-step] [data-step]');
        if (!button) return;
        var input = button.closest('[data-score-step]').querySelector('input');
        if (!input) return;
        var step = parseInt(button.getAttribute('data-step'), 10) || 0;
        var min = input.min === '' ? 0 : parseInt(input.min, 10);
        var max = input.max === '' ? Infinity : parseInt(input.max, 10);
        var current = input.value === '' ? null : parseInt(input.value, 10);
        // Campo vazio: o primeiro + marca 1 e o primeiro − marca 0.
        var next = current === null || isNaN(current) ? (step > 0 ? min + 1 : min) : current + step;
        input.value = String(Math.max(min, Math.min(max, next)));
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
})();
