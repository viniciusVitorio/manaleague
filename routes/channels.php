<?php

use App\Models\Challenge;
use App\Models\LifeTable;
use App\Models\RankedMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Life\LifeTableService;
use Illuminate\Support\Facades\Broadcast;

/*
 * Autorizacao dos canais privados do tempo real (POST /broadcasting/auth, com sessao e CSRF).
 * Mesmas regras das paginas: quem nao pode ver a tela nao escuta o canal dela.
 * O canal so avisa "mudou" (exceto o do contador de vida, que leva as jogadas da mesa).
 */

Broadcast::channel('user.{id}', fn (User $user, string $id) => $user->id === (int) $id);

Broadcast::channel('tournament.{id}', function (User $user, string $id) {
    $tournament = Tournament::find((int) $id);

    return $tournament !== null && $user->can('view', $tournament);
});

Broadcast::channel('challenge.{id}', function (User $user, string $id) {
    $challenge = Challenge::with(['challenger', 'opponent'])->find((int) $id);

    return $challenge !== null && $challenge->involves($user);
});

Broadcast::channel('ranked.{id}', function (User $user, string $id) {
    $match = RankedMatch::find((int) $id);

    return $match !== null && $user->can('view', $match);
});

Broadcast::channel('life.{id}', function (User $user, string $id) {
    $table = LifeTable::find((int) $id);

    return $table !== null && app(LifeTableService::class)->allows($user, $table);
});
