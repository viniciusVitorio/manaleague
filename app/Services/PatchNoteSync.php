<?php

namespace App\Services;

use App\Models\CommunityNotification;
use App\Models\PatchNote;
use App\Models\User;
use App\Services\Community\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Espelha as GitHub Releases publicadas na tabela patch_notes.
 * Recebe a lista completa: cria/atualiza o que veio e apaga o que nao veio (release removida no GitHub).
 * Uma versao nova avisa todos os jogadores no sino de notificacoes.
 */
class PatchNoteSync
{
    public const MAX_BODY = 20000;

    public const NOTIFICATION_TYPE = 'patch_note.published';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  mixed  $releases  lista de {tag, name, body, published_at} (formato gerado pela pipeline)
     * @return array{created: int, updated: int, deleted: int, announced: ?string, notified: int}
     */
    public function sync(mixed $releases): array
    {
        $rows = $this->validate($releases);

        [$result, $announce] = DB::transaction(function () use ($rows): array {
            $result = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'announced' => null, 'notified' => 0];
            $previousNewest = PatchNote::newestFirst()->first();
            $created = [];

            foreach ($rows as $row) {
                $note = PatchNote::firstOrNew(['version' => $row['version']]);
                $note->fill($row);
                if (! $note->exists) {
                    $result['created']++;
                    $created[] = $note;
                } elseif ($note->isDirty()) {
                    $result['updated']++;
                }
                $note->save();
            }

            $gone = PatchNote::whereNotIn('version', array_column($rows, 'version'))->pluck('version');
            if ($gone->isNotEmpty()) {
                PatchNote::whereIn('version', $gone)->delete();
                // Release apagada: o aviso apontaria para uma versao que nao existe mais.
                CommunityNotification::where('type', self::NOTIFICATION_TYPE)
                    ->whereIn('event_key', $gone->map(fn (string $version) => $this->eventKey($version)))->delete();
            }
            $result['deleted'] = $gone->count();

            // So a versao nova mais recente vira aviso (uma importacao inicial com varias releases nao lota o sino),
            // e so se ela for mais nova que tudo o que ja existia (release antiga cadastrada depois nao avisa).
            $announce = collect($created)->sortByDesc('published_at')->first();
            if ($announce && $previousNewest && ! $announce->published_at->gt($previousNewest->published_at)) {
                $announce = null;
            }

            return [$result, $announce];
        });

        if ($announce) {
            $result['announced'] = $announce->version;
            $result['notified'] = $this->announce($announce);
        }

        return $result;
    }

    /** Avisa cada conta uma unica vez por versao (event_key unico por usuario); respeita quem silenciou. */
    public function announce(PatchNote $note): int
    {
        $title = $note->title === $note->version
            ? "Nova versão {$note->version} no ar"
            : "Nova versão {$note->version}: {$note->title}";
        $url = '/patch-notes#'.rawurlencode($note->version);

        User::query()->select('id')->lazyById(200)->each(fn (User $user) => $this->notifications->send(
            $user, self::NOTIFICATION_TYPE, Str::limit($title, 180), $url, $this->eventKey($note->version), ['version' => $note->version],
        ));

        return CommunityNotification::where('event_key', $this->eventKey($note->version))->count();
    }

    private function eventKey(string $version): string
    {
        return 'patch_note:'.$version;
    }

    /** Valida tudo antes de gravar: entrada ruim nao apaga nem altera nada. */
    private function validate(mixed $releases): array
    {
        if (! is_array($releases) || ! array_is_list($releases)) {
            throw new InvalidArgumentException('Esperava uma lista JSON de releases.');
        }

        $rows = [];
        foreach ($releases as $i => $release) {
            $tag = is_array($release) ? ($release['tag'] ?? null) : null;
            if (! is_string($tag) || ! preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,49}$/', $tag)) {
                throw new InvalidArgumentException("Release #{$i}: tag ausente ou invalida.");
            }
            if (isset($rows[$tag])) {
                throw new InvalidArgumentException("Release #{$i}: tag {$tag} repetida.");
            }
            $published = $release['published_at'] ?? null;
            if (! is_string($published) || $published === '') {
                throw new InvalidArgumentException("Release {$tag}: published_at ausente.");
            }
            try {
                $publishedAt = Carbon::parse($published);
            } catch (\Throwable) {
                throw new InvalidArgumentException("Release {$tag}: published_at invalido.");
            }

            $name = is_string($release['name'] ?? null) ? trim($release['name']) : '';
            $body = is_string($release['body'] ?? null) ? trim($release['body']) : '';
            $rows[$tag] = [
                'version' => $tag,
                'title' => Str::limit($name !== '' ? $name : $tag, 117),
                'body' => $body !== '' ? Str::limit($body, self::MAX_BODY) : null,
                // O Eloquent grava a data sem fuso: converte para o fuso do app para ler de volta o mesmo instante.
                'published_at' => $publishedAt->setTimezone(config('app.timezone')),
            ];
        }

        return array_values($rows);
    }
}
