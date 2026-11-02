// Campo de avatar com só 1 emoji (input[data-single-emoji]): digitar ou colar outro troca o atual; letras e números são descartados.
// O servidor valida de novo; aqui é só para a pessoa não descobrir a regra ao salvar.
(function () {
    'use strict';
    var PICTO = /\p{Extended_Pictographic}/u;
    // Sem Intl.Segmenter: um emoji com tom de pele, variação e junções (ZWJ), como 🧙‍♂️ ou 👍🏽.
    var SEQUENCE = /\p{Extended_Pictographic}(?:️|\p{Emoji_Modifier})?(?:‍\p{Extended_Pictographic}(?:️|\p{Emoji_Modifier})?)*/gu;
    var segmenter = typeof Intl !== 'undefined' && Intl.Segmenter ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null;

    function emojis(value) {
        if (!segmenter) return value.match(SEQUENCE) || [];
        var list = [];
        for (var part of segmenter.segment(value)) if (PICTO.test(part.segment)) list.push(part.segment);
        return list;
    }

    function clamp(input) {
        var list = emojis(input.value);
        var next = list.length ? list[list.length - 1] : '';
        if (next !== input.value) input.value = next;
    }

    function enhance(input) {
        if (input.dataset.singleEmojiReady) return;
        input.dataset.singleEmojiReady = '1';
        input.addEventListener('input', function (event) {
            if (event.isComposing) return; // espera o teclado (IME) terminar
            clamp(input);
        });
        input.addEventListener('compositionend', function () { clamp(input); });
        clamp(input);
    }

    function init() { document.querySelectorAll('input[data-single-emoji]').forEach(enhance); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
