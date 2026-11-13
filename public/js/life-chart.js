/* Gráfico da partida: linha vertical que gruda no lance mais próximo e mostra a vida de todos naquele momento.
 * Escuta o documento inteiro (o gráfico também chega depois, dentro da janela da lista). Teclado: setas no gráfico. */
(function () {
    'use strict';

    var cache = new WeakMap();

    function dataOf(plot) {
        var fig = plot.closest('[data-life-chart]');
        if (!fig) return null;
        if (!cache.has(fig)) {
            try { cache.set(fig, JSON.parse(fig.getAttribute('data-life-chart'))); } catch (e) { return null; }
        }
        return cache.get(fig);
    }

    function clock(seconds) {
        var m = Math.floor(seconds / 60), s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function nearest(data, t) {
        var best = 0;
        data.steps.forEach(function (step, i) {
            if (Math.abs(step.t - t) < Math.abs(data.steps[best].t - t)) best = i;
        });
        return best;
    }

    function show(plot, index) {
        var data = dataOf(plot);
        if (!data) return;
        var step = data.steps[Math.max(0, Math.min(index, data.steps.length - 1))];
        var x = data.duration ? step.t / data.duration * 100 : 0;
        var cross = plot.querySelector('.lchart-cross'), tip = plot.querySelector('.lchart-tip');
        plot.setAttribute('data-index', String(index));
        cross.style.left = x + '%';
        cross.hidden = false;

        // Nomes vêm do servidor, mas entram sempre como texto.
        tip.textContent = '';
        var head = document.createElement('p');
        head.className = 'lchart-tip-time';
        head.textContent = index === 0 ? 'Início' : clock(step.t);
        tip.appendChild(head);
        step.values.forEach(function (life, seat) {
            var row = document.createElement('p');
            var key = document.createElement('i');
            key.className = 'lchart-key s' + (seat + 1);
            var value = document.createElement('b');
            value.textContent = String(life);
            row.append(key, value, document.createTextNode(' ' + (data.names[seat] || '')));
            tip.appendChild(row);
        });
        tip.hidden = false;
        // Abre à direita da linha; sem espaço, à esquerda; no celular, onde não cabe de nenhum lado, fica presa dentro do gráfico.
        var width = plot.clientWidth, at = width * x / 100, size = tip.offsetWidth;
        var left = at + 10 + size <= width ? at + 10 : at - 10 - size;
        tip.style.left = Math.max(0, Math.min(width - size, left)) + 'px';
    }

    function hide(plot) {
        plot.querySelector('.lchart-cross').hidden = true;
        plot.querySelector('.lchart-tip').hidden = true;
        plot.removeAttribute('data-index');
    }

    document.addEventListener('pointermove', function (event) {
        var plot = event.target.closest && event.target.closest('.lchart-plot');
        if (!plot) return;
        var data = dataOf(plot);
        if (!data) return;
        var box = plot.getBoundingClientRect();
        var ratio = Math.max(0, Math.min(1, (event.clientX - box.left) / box.width));
        show(plot, nearest(data, ratio * data.duration));
    });

    document.addEventListener('pointerout', function (event) {
        var plot = event.target.closest && event.target.closest('.lchart-plot');
        if (plot && !plot.contains(event.relatedTarget) && document.activeElement !== plot) hide(plot);
    });

    document.addEventListener('keydown', function (event) {
        var plot = event.target.closest && event.target.closest('.lchart-plot');
        if (!plot || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' && event.key !== 'Home' && event.key !== 'End')) return;
        var data = dataOf(plot);
        if (!data) return;
        event.preventDefault();
        var current = plot.hasAttribute('data-index') ? Number(plot.getAttribute('data-index')) : -1;
        var last = data.steps.length - 1;
        var next = event.key === 'Home' ? 0 : event.key === 'End' ? last : current + (event.key === 'ArrowRight' ? 1 : -1);
        show(plot, Math.max(0, Math.min(last, next)));
    });

    document.addEventListener('focusout', function (event) {
        if (event.target.classList && event.target.classList.contains('lchart-plot')) hide(event.target);
    });
})();
