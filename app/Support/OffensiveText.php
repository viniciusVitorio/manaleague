<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Filtro simples de texto ofensivo para nomes que aparecem no perfil de outras pessoas (ex.: nome de troféu).
 * Compara palavra por palavra, sem acento e desfazendo trocas comuns de letra por número ("m3rd4"), para não
 * barrar palavra inocente que só contém o trecho. Não substitui moderação: segura o óbvio.
 */
class OffensiveText
{
    private const WORDS = [
        'merda', 'bosta', 'porra', 'caralho', 'cacete', 'puta', 'puto', 'putaria', 'fdp', 'pqp', 'vsf', 'tnc', 'foda', 'fodase', 'foder',
        'cu', 'cuzao', 'buceta', 'boceta', 'xoxota', 'piroca', 'rola', 'penis', 'vagina', 'punheta', 'viado', 'viadinho',
        'bicha', 'bichona', 'traveco', 'sapatao', 'arrombado', 'arrombada', 'otario', 'otaria', 'babaca', 'idiota', 'imbecil', 'retardado',
        'retardada', 'mongol', 'mongoloide', 'vagabunda', 'vagabundo', 'vadia', 'piranha', 'corno', 'crioulo', 'nazi',
        'nazista', 'hitler', 'estupro', 'estuprador', 'pedofilo', 'fuck', 'fucker', 'fucking', 'shit', 'bitch', 'cunt', 'dick', 'cock',
        'pussy', 'asshole', 'nigger', 'nigga', 'faggot', 'fag', 'retard', 'whore', 'slut', 'rape',
    ];

    public static function contains(?string $text): bool
    {
        $plain = strtr(Str::lower(Str::ascii((string) $text)), ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']);
        $words = preg_split('/[^a-z]+/', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // "f.d.p" e "p u t a" viram uma palavra só quando sobram apenas letras soltas.
        $spelled = count($words) > 1 && max(array_map('strlen', $words)) === 1 ? [implode('', $words)] : [];

        // O plural simples conta como a mesma palavra ("merdas").
        $singular = array_map(fn (string $word) => strlen($word) > 3 ? rtrim($word, 's') : $word, $words);

        return array_intersect([...$words, ...$singular, ...$spelled], self::WORDS) !== [];
    }
}
