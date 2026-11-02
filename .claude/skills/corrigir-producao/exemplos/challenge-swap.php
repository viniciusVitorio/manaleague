<?php
// Inverte o placar de um desafio confirmado (quem ganhou vira quem perdeu).
// Sem APPLY=1 só mostra o que mudaria. Uso: CHALLENGE_ID=123 [APPLY=1]
use App\Models\{Challenge, CommunityActivity, RankedMatch};
use App\Services\AchievementService;
use App\Services\Ranked\RatingReplayService;
use Illuminate\Support\Facades\DB;

$id = (int) getenv('CHALLENGE_ID');
$apply = getenv('APPLY') === '1';
$c = Challenge::with(['challenger', 'opponent', 'casualMatch'])->find($id);
if (! $c) { echo "Desafio #{$id} não encontrado.\n"; return; }
$ranked = $c->is_ranked ? RankedMatch::with('season.formats')->where(['source_type' => 'challenge', 'source_id' => $c->id])->first() : null;
$match = $ranked ?? $c->casualMatch;
if (! $match || $match->status !== 'confirmed') { echo "O desafio #{$id} não tem placar confirmado. Nada a fazer.\n"; return; }

[$oneKey, $twoKey] = $ranked ? ['score_one', 'score_two'] : ['challenger_games', 'opponent_games'];
$one = $match->{$oneKey};
$two = $match->{$twoKey};
$order = $ranked ? null : $match->game_order;
$swappedOrder = $order ? array_map(fn ($side) => $side === 1 ? 2 : 1, $order) : $order;
$names = [$c->challenger->nickname, $c->opponent->nickname];
$event = CommunityActivity::where('event_key', "challenge:{$c->id}:completed")->first();
$newTitle = "{$names[0]} {$two}×{$one} {$names[1]}";

echo ($c->is_ranked ? 'Ranqueado' : 'Casual')." #{$c->id}: {$names[0]} {$one} × {$two} {$names[1]}  →  {$names[0]} {$two} × {$one} {$names[1]}\n";
if ($order) echo 'Ordem dos games: '.implode(',', $order).' → '.implode(',', $swappedOrder)."\n";
if ($event) echo "Feed: \"{$event->title}\" → \"{$newTitle}\"\n";
if ($ranked && ! in_array($ranked->season->status, ['active', 'closing'], true)) { echo "A temporada já foi encerrada: o Elo não pode mais ser corrigido. Nada foi alterado.\n"; return; }
if (! $apply) { echo "\nSimulação: nada foi gravado. Rode de novo com APPLY=1 para corrigir.\n"; return; }

$changed = DB::transaction(function () use ($match, $oneKey, $twoKey, $one, $two, $ranked, $swappedOrder, $event, $newTitle, $c) {
    $match->forceFill([$oneKey => $two, $twoKey => $one] + ($ranked ? [] : ['game_order' => $swappedOrder]))->save();
    $event?->forceFill(['title' => $newTitle])->save();

    return $ranked ? app(RatingReplayService::class)->replay($ranked->season, $ranked->format, $ranked, "Desafio #{$c->id}: placar invertido a pedido dos jogadores") : [];
}, 3);
foreach ($changed as $profileId => $r) echo "Elo do perfil #{$profileId}: {$r['before']} → {$r['after']}\n";

// Conquistas: libera as que o placar novo dá e tira as que só vieram do placar errado
// (desbloqueadas depois da confirmação e que a pessoa não alcança mais; especiais nunca saem).
$achievements = app(AchievementService::class);
$definitions = AchievementService::definitions();
$since = $match->confirmed_at ?? $match->updated_at;
foreach ([$c->challenger, $c->opponent] as $profile) {
    $profile = $profile->fresh();
    $metrics = $achievements->metrics($profile);
    foreach ($profile->achievements()->where('unlocked_at', '>=', $since)->get() as $record) {
        [, $name, , , $target, $metric] = $definitions[$record->achievement] ?? [null, null, null, null, 0, 'granted'];
        if ($metric !== 'granted' && ($metrics[$metric] ?? 0) < $target) { $record->delete(); echo "{$profile->nickname}: conquista removida ({$name})\n"; }
    }
    foreach ($achievements->sync($profile) as $key) echo "{$profile->nickname}: conquista liberada ({$definitions[$key][1]})\n";
}
echo "Pronto: desafio #{$c->id} agora está {$names[0]} {$two} × {$one} {$names[1]}.\n";
