<?php
// Só leitura: lista os últimos desafios confirmados de quem tem SEARCH no apelido (ex.: SEARCH=Vitorio).
$search = getenv('SEARCH') ?: '';
$ids = App\Models\PlayerProfile::query()->where('nickname', 'like', "%{$search}%")->pluck('id');
$challenges = App\Models\Challenge::query()->with(['challenger', 'opponent', 'casualMatch'])
    ->where(fn ($q) => $q->whereIn('challenger_id', $ids)->orWhereIn('opponent_id', $ids))
    ->latest('id')->limit(10)->get();

foreach ($challenges as $c) {
    $ranked = $c->is_ranked ? App\Models\RankedMatch::where(['source_type' => 'challenge', 'source_id' => $c->id])->first() : null;
    [$one, $two, $status] = $ranked ? [$ranked->score_one, $ranked->score_two, $ranked->status] : [$c->casualMatch?->challenger_games, $c->casualMatch?->opponent_games, $c->casualMatch?->status];
    echo sprintf("#%d  %s  %s %s × %s %s  (%s, placar %s, %s)\n",
        $c->id, $c->created_at->format('d/m/Y H:i'), $c->challenger->nickname, $one ?? '-', $two ?? '-', $c->opponent->nickname,
        $c->is_ranked ? 'ranqueado' : 'casual', $status ?? '-', $c->status);
}
if ($challenges->isEmpty()) echo "Nenhum desafio encontrado para \"{$search}\".\n";
