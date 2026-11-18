// Formulário de premiação: mostra a prévia da imagem escolhida para o troféu (no mesmo quadrado em que ela vai aparecer)
// e avisa na hora se o arquivo não serve. O servidor valida de novo e é ele quem ajusta para o quadrado final.
(function () {
    'use strict';
    var TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    // Delegado no document: continua valendo depois de um envio sem recarregar (js/async-forms.js).
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input.matches || !input.matches('[data-prize-image]')) return;
        var box = input.closest('[data-prize-image-box]');
        var note = box && box.querySelector('[data-prize-image-note]');
        var preview = box && box.querySelector('[data-prize-image-preview]');
        var file = input.files && input.files[0];
        if (!box || !file) return;

        function refuse(message) {
            input.value = '';
            if (note) { note.textContent = message; note.classList.add('is-error'); }
        }

        var maxKb = parseInt(input.getAttribute('data-max-kb'), 10) || 0;
        if (TYPES.indexOf(file.type) === -1) return refuse('Envie uma imagem JPG, PNG ou WebP.');
        if (maxKb && file.size > maxKb * 1024) return refuse('Essa imagem é grande demais. O máximo é ' + (maxKb / 1024).toLocaleString('pt-BR') + ' MB.');

        if (note) { note.textContent = 'Nova imagem: ' + file.name; note.classList.remove('is-error'); }
        if (!preview || !window.FileReader) return;
        // data: (e não blob:) porque é o que a CSP do site permite em imagens.
        var reader = new FileReader();
        reader.onload = function () {
            var frame = document.createElement('span');
            frame.className = 'prize-icon has-image';
            frame.setAttribute('aria-hidden', 'true');
            var image = document.createElement('img');
            image.alt = '';
            image.src = reader.result;
            frame.appendChild(image);
            preview.textContent = '';
            preview.appendChild(frame);
        };
        reader.readAsDataURL(file);
    });
})();
