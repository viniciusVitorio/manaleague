@extends('layouts.app')
@section('content')
@php
    $isOwner = $profile->account_user_id === auth()->id();
    $unlocked = $achievements->where('unlocked', true);
    $fresh = $achievements->where('is_new', true);
@endphp
<header class="profile-hero">
    <div>
        <a class="back-link" href="{{ $isOwner ? route('tournaments.index') : route('friends.index') }}">← {{ $isOwner ? 'Torneios' : 'Amigos' }}</a>
        <div class="profile-title">
            <span class="profile-avatar large">{{ $profile->avatar ?: mb_strtoupper(mb_substr($profile->nickname, 0, 1)) }}</span>
            <div>
                <span class="eyebrow">{{ $isOwner ? 'Meu perfil' : 'Perfil do jogador' }}</span>
                <h1>{{ $profile->nickname }}</h1>
                @if($ranked && $ranked['ratings']->isNotEmpty())<p><a href="#ranque">@include('ranked.partials.tier', ['rating' => $ranked['ratings']->first()->rating, 'tiers' => $ranked['tiers']])</a> <span class="muted">{{ $ranked['ratings']->first()->format }} · {{ $ranked['ratings']->first()->rating }} ELO</span></p>@endif

                @if($showPreferredDeck)<p>{{ $profile->preferred_deck_name ?: 'Deck principal não informado' }} @if($profile->preferred_deck_colors) · @include('partials.color-pips', ['colors' => $profile->preferred_deck_colors]) @endif</p>@endif



                <div class="profile-meta">
                    <span>🏆 {{ $unlocked->count() }}/{{ $achievements->count() }} conquistas</span>
                    @if($isOwner && $friendsCount !== null)<a href="{{ route('friends.index') }}">🤝 {{ $friendsCount }} {{ $friendsCount === 1 ? 'amigo' : 'amigos' }}</a>@endif
                    @if(! $isOwner && ($profileFriends ?? null) !== null && $profileFriends->isNotEmpty())
                    <details class="friends-pop" id="amigos">
                        <summary>🤝 {{ $profileFriends->count() }} {{ $profileFriends->count() === 1 ? 'amigo' : 'amigos' }}</summary>
                        <div class="friends-pop-panel" role="list">
                        @foreach($profileFriends as $person)
                            <div class="friends-pop-row" role="listitem">
                                <span class="profile-avatar">{{ $person->avatar ?: mb_strtoupper(mb_substr($person->nickname, 0, 1)) }}</span>
                                <span class="friends-pop-name">@if($person->friend_state === 'me'){{ $person->nickname }}<small>Você</small>@else<a href="{{ route('profiles.show', $person) }}">{{ $person->nickname }}</a><small>{{ $person->friend_state === 'friend' ? 'Amigo em comum' : ($person->friend_state === 'sent' ? 'Pedido enviado' : 'Amigo de '.$profile->nickname) }}</small>@endif</span>
                                @if($person->friend_state === 'incoming')<form method="POST" action="{{ route('friends.accept-code', $person->friend_code) }}">@csrf @method('PATCH')<button class="ui-btn ui-btn-outline ui-btn-sm">Aceitar</button></form>
                                @elseif($person->friend_state === 'none')<form method="POST" action="{{ route('friends.store', $person->friend_code) }}">@csrf<button class="ui-btn ui-btn-ghost ui-btn-sm" aria-label="Adicionar {{ $person->nickname }}"><x-icon name="user-plus" /></button></form>@endif
                            </div>
                        @endforeach
                        </div>
                    </details>
                    @endif
                    @if($isOwner)<span>Código: <b>{{ $profile->formattedFriendCode() }}</b></span>@endif
                </div>
            </div>
        </div>
    </div>
    @if($isOwner)
        <div class="deck-actions"><a class="btn btn-secondary" href="{{ route('challenges.index') }}">Desafios 1v1</a><a class="btn btn-secondary" href="{{ route('challenges.rivalries') }}">Rivalidades</a><a class="btn btn-primary" href="{{ route('profiles.edit') }}">Editar perfil e decks</a></div>
    @else
        <form method="POST" action="{{ route('profiles.friend', $profile) }}">@csrf @method('PATCH')<button class="btn btn-secondary">{{ match($friendshipStatus) { 'pending' => 'Cancelar solicitação', 'accepted' => 'Remover amizade', default => 'Solicitar amizade' } }}</button></form>
    @endif
</header>




@if($isOwner && $fresh->isNotEmpty())
<section class="card unlock-banner">
    <span class="unlock-burst">🎉</span>
    <div><span class="eyebrow">Conquista desbloqueada</span><h2>{{ $fresh->map(fn ($a) => $a['icon'].' '.$a['name'])->implode(' · ') }}</h2></div>
</section>
@endif


@if($isOwner && $friendRequests->isNotEmpty())
<section class="card section-card">
    <span class="eyebrow">Novas conexões</span><h2>Solicitações de amizade</h2>
    @foreach($friendRequests as $requester)
        <div class="history-row"><div><strong>{{ $requester->playerProfile?->avatar ? $requester->playerProfile->avatar.' ' : '' }}{{ $requester->playerProfile?->nickname ?? $requester->name }}</strong><small>quer ser seu amigo no ManaLeague</small></div><div class="deck-actions"><form method="POST" action="{{ route('friends.accept', $requester) }}">@csrf @method('PATCH')<button class="btn btn-small btn-primary">Aceitar</button></form><form method="POST" action="{{ route('friends.reject', $requester) }}">@csrf @method('DELETE')<button class="btn btn-small btn-ghost">Recusar</button></form></div></div>
    @endforeach
</section>
@endif








<div class="stat-grid">
    <article class="card stat-card"><b>{{ $summary['wins'] }}–{{ $summary['losses'] }}@if($summary['draws'])–{{ $summary['draws'] }}@endif</b><span>Vitórias / derrotas</span></article>
    <article class="card stat-card"><b>{{ $summary['games_for'] }}–{{ $summary['games_against'] }}</b><span>Games</span></article>
    <article class="card stat-card"><b>{{ $summary['winrate'] }}%</b><span>Aproveitamento</span></article>
    <article class="card stat-card"><b>{{ $summary['best_streak'] }}</b><span>Maior sequência</span></article>
</div>


@if($ranked)
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/ranked.css') }}">@endpush
<section class="card section-card profile-ranked" id="ranque">
    <div class="section-heading"><div><span class="eyebrow">ELO / PDL</span><h2>Ranque</h2></div>@if($isOwner && $ranked['activeFormats']->isNotEmpty())<a class="btn btn-small btn-secondary" href="{{ route('challenges.index', ['ranked' => 1]) }}">Desafio ranqueado</a>@endif</div>
    <div class="rank-cards">
    @forelse($ranked['ratings'] as $rating)
        @include('ranked.partials.rank-card', ['rating' => $rating->rating, 'tiers' => $ranked['tiers'], 'label' => $rating->format.' · '.$rating->season->name, 'record' => $rating])
    @empty
        @include('ranked.partials.rank-card', ['rating' => $ranked['start'], 'tiers' => $ranked['tiers'], 'label' => $ranked['currentSeason']?->name ?? 'Sem temporada ativa', 'record' => null])
    @endforelse
    </div>
    @if($ranked['ratings']->isEmpty())
        <p class="muted rank-hint">@if($isOwner)@if($ranked['currentSeason'])Você ainda não jogou ranqueado. Faça um desafio ranqueado com um amigo para estrear; todos começam em {{ $ranked['start'] }} ELO em cada formato.@else O ranque abre assim que a próxima temporada começar.@endif @else Ainda sem partidas ranqueadas nesta temporada.@endif</p>
    @endif
    @include('ranked.partials.all-tiers', ['rating' => $ranked['ratings']->max('rating') ?? $ranked['start'], 'tiers' => $ranked['tiers']])
    @if($ranked['history']->isNotEmpty())
        <h3 class="achievement-category">Últimas variações</h3>
        @foreach($ranked['history'] as $tx)
            <div class="tx-row"><span class="delta {{ $tx->delta >= 0 ? 'up' : 'down' }}">{{ $tx->delta >= 0 ? '+' : '' }}{{ $tx->delta }}</span><div>@if($tx->event_type === 'correction')<strong>Correção da moderação</strong><small>{{ $tx->note }}</small>@else<strong>@if($isOwner)<a href="{{ route('ranked.matches.show', $tx->ranked_match_id) }}">@endif{{ $tx->rankedMatch?->playerOne?->nickname }} {{ $tx->rankedMatch?->score_one }}×{{ $tx->rankedMatch?->score_two }} {{ $tx->rankedMatch?->playerTwo?->nickname }}@if($isOwner)</a>@endif</strong><small>{{ $tx->format }} · {{ $tx->rating_before }} → {{ $tx->rating_after }}</small>@endif</div><time datetime="{{ $tx->effective_at->toIso8601String() }}">{{ $tx->effective_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</time></div>
        @endforeach
    @endif
    @if($ranked['snapshots']->isNotEmpty())
        <h3 class="achievement-category">Temporadas encerradas</h3>
        @foreach($ranked['snapshots'] as $snap)<p class="muted"><strong>{{ $snap->season->name }}</strong> · {{ $snap->format }}: {{ $snap->tier }} ({{ $snap->final_rating }})@if($snap->rank) · {{ $snap->rank }}º lugar @endif</p>@endforeach
    @endif
</section>
@endif
@if(($tradeBinder ?? null) !== null && $tradeBinder->isNotEmpty())
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/hub.css') }}">@endpush
<section class="card section-card" id="trocas">
    <div class="section-heading"><div><span class="eyebrow">Fichário</span><h2>Para troca <span class="count">{{ $tradeBinder->sum('for_trade') }}</span></h2></div></div>
    <p class="muted small">Cartas que {{ $profile->nickname }} topa trocar. Combine pelo desafio, no clube ou na mesa.</p>
    <div class="binder">
    @foreach($tradeBinder as $item)
        <article class="binder-card"><div class="binder-img">@if($item->image_url)<img src="{{ $item->image_url }}" alt="" loading="lazy" width="146" height="204">@else<span>{{ $item->name }}</span>@endif @if($item->foil)<span class="binder-flag">Foil</span>@endif<span class="binder-flag is-trade">{{ $item->for_trade }}×</span></div>
        <div class="binder-info"><strong title="{{ $item->name }}">{{ $item->name }}</strong><small>{{ $item->set_code }} · {{ $item->condition }}@if($item->price_usd) · US$ {{ number_format((float) $item->price_usd, 2, ',', '.') }}@endif</small></div></article>
    @endforeach
    </div>
</section>
@endif
<section class="card section-card">
    <div class="section-heading"><div><span class="eyebrow">Badges</span><h2>Conquistas</h2></div><span class="muted">{{ $unlocked->count() }} de {{ $achievements->count() }}</span></div>
    <div class="achievement-progress"><span style="width: {{ round($unlocked->count() / max(1, $achievements->count()) * 100) }}%"></span></div>
    @foreach(\App\Services\AchievementService::CATEGORIES as $category => $label)
        <h3 class="achievement-category">{{ $label }}</h3>
        <div class="achievement-grid">
        @foreach($achievements->where('category', $category)->sortByDesc('unlocked') as $achievement)
            <article class="achievement {{ $achievement['unlocked'] ? 'unlocked' : 'locked' }} {{ $achievement['is_new'] ? 'is-new' : '' }}" title="{{ $achievement['rarity'] }}% dos jogadores têm esta conquista">
                <span class="achievement-icon">{{ $achievement['icon'] }}</span>
                <div>
                    <strong>{{ $achievement['name'] }} @if($achievement['is_new'])<em class="new-tag">Nova!</em>@endif</strong>
                    <small>{{ $achievement['description'] }}</small>
                    @if($achievement['unlocked'])
                        <small class="achievement-date">{{ $achievement['unlocked_at']?->format('d/m/Y') }} · {{ $achievement['rarity'] <= 10 ? 'Rara' : ($achievement['rarity'] <= 35 ? 'Incomum' : 'Comum') }} ({{ $achievement['rarity'] }}%)</small>
                    @elseif($achievement['target'] > 1)
                        <div class="mini-progress" aria-label="Progresso {{ $achievement['current'] }} de {{ $achievement['target'] }}"><span style="width: {{ $achievement['percent'] }}%"></span></div>
                        <small class="achievement-date">{{ $achievement['current'] }}/{{ $achievement['target'] }}</small>
                    @endif
                </div>
            </article>
        @endforeach
        </div>
    @endforeach
</section>

@if($decks->isNotEmpty() || $isOwner)
<section class="card section-card">
    <div class="section-heading"><div><span class="eyebrow">Arsenal</span><h2>Decks</h2></div>@if($isOwner)<a class="btn btn-small btn-secondary" href="{{ route('profiles.edit') }}#novo-deck">Novo deck</a>@endif</div>
    <div class="deck-grid">
    @forelse($decks as $deck)
        <a class="deck-tile" href="{{ route('decks.show', $deck) }}">
            <div><strong>{{ $deck->is_primary ? '★ ' : '' }}{{ $deck->name }}</strong><small>{{ $deck->format ?: 'Formato livre' }} · {{ (int) $deck->cards_sum_quantity }} cartas</small></div>
            @include('partials.color-pips', ['colors' => $deck->colors])


            @if($isOwner && !$deck->is_visible_to_friends)<small class="muted">🔒 Privado</small>@endif

        </a>
    @empty
        <p class="muted">Nenhum deck cadastrado ainda.</p>
    @endforelse
    </div>
</section>
@endif

<div class="profile-columns">
    <section class="card section-card"><span class="eyebrow">Arsenal utilizado</span><h2>Decks nos torneios</h2>
        @forelse($summary['decks'] as $deck)<div class="history-row"><div><strong>{{ $deck['name'] }}</strong><small>@if($deck['colors'])@include('partials.color-pips', ['colors' => $deck['colors']])@else Cores não informadas @endif</small></div><b>{{ $deck['wins'] }}W — {{ $deck['losses'] }}L</b></div>@empty<p class="muted">Nenhum deck registrado.</p>@endforelse
    </section>
    <section class="card section-card"><span class="eyebrow">Histórico</span><h2>Campeonatos</h2>
        @forelse($summary['tournaments'] as $tournament)<a class="history-row" href="{{ route('arena.public', $tournament->public_slug) }}"><div><strong>{{ $tournament->name }}</strong><small>{{ $tournament->format }} · {{ $tournament->tournament_date?->format('d/m/Y') }}</small></div><span>→</span></a>@empty<p class="muted">Nenhum torneio disputado.</p>@endforelse
    </section>
</div>




@endsection
