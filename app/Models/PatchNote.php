<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Uma GitHub Release publicada (tag = versao, corpo = texto do patch note). */
class PatchNote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * Corpo em texto plano dividido em blocos para a view (que escapa tudo).
     * Linhas "- item" viram lista, "## Titulo" vira subtitulo; o resto vira paragrafo.
     *
     * @return list<array{type: 'heading'|'list'|'paragraph', text?: string, items?: list<string>}>
     */
    public function blocks(): array
    {
        $blocks = [];
        $paragraph = [];
        $list = [];
        $flush = function () use (&$blocks, &$paragraph, &$list): void {
            if ($paragraph) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode("\n", $paragraph)];
                $paragraph = [];
            }
            if ($list) {
                $blocks[] = ['type' => 'list', 'items' => $list];
                $list = [];
            }
        };

        foreach (preg_split('/\R/u', (string) $this->body) as $line) {
            $line = rtrim($line);
            if (trim($line) === '') {
                $flush();
            } elseif (preg_match('/^#{1,6}\s+(.+)$/u', $line, $m)) {
                $flush();
                $blocks[] = ['type' => 'heading', 'text' => trim($m[1])];
            } elseif (preg_match('/^\s*[-*+]\s+(.+)$/u', $line, $m)) {
                if ($paragraph) {
                    $flush();
                }
                $list[] = trim($m[1]);
            } else {
                if ($list) {
                    $flush();
                }
                $paragraph[] = trim($line);
            }
        }
        $flush();

        return $blocks;
    }
}
