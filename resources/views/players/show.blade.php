@extends('layouts.app')
@section('content')
@php
    $isOwner = $profile->account_user_id === auth()->id();
    $unlocked = $achievements->where('unlocked', true);
    $fresh = $achievements->where('is_new', true);
@endphp
@php
    // Visitante chega pelo ranking, desafios, clube... volta para onde estava (só páginas do próprio site).
    $previous = url()->previous();
    $backUrl = ! $isOwner && $previous !== url()->current() && str_starts_with($previous, url('/').'/') ? $previous : null;
@endphp
@push('head')<script src="{{ \App\Support\VersionedAsset::url('js/copy-link.js') }}" defer></script>@endpush
<a class="back-link" href="{{ $isOwner ? route('tournaments.index') : ($backUrl ?? route('friends.index')) }}">← {{ $isOwner ? 'Torneios' : ($backUrl ? 'Voltar' : 'Amigos') }}</a>
<header class="profile-hero card">
    <div>
        <div class="profile-title">
            <x-avatar :of="$profile" class="profile-avatar large" />
            <div>
                <span class="eyebrow">{{ $isOwner ? 'Meu perfil' : 'Perfil do jogador' }}</span>
                <div class="profile-name-line">
                    <h1>{{ $profile->nickname }}</h1>
                    <button type="button" class="ui-icon-btn profile-copy-link" data-copy-link="{{ route('profiles.show', $profile) }}" aria-label="Copiar link do perfil" title="Copiar link do perfil"><x-icon name="link" /></button>
                    <span class="profile-copy-status" data-copy-status role="status" aria-live="polite"></span>
                </div>
                <p class="profile-handle">{{ '@'.$profile->handle }}</p>
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
                                <x-profile-link :of="$person" class="avatar-link" tabindex="-1" aria-hidden="true"><x-avatar :of="$person" class="profile-avatar" /></x-profile-link>
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
    {{-- Card de identidade: so uma acao secundaria, no canto (regra em docs/ui-rules.md). Navegacao fica no menu. --}}
    <div class="profile-hero-action">
    @if($isOwner)
        <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('profiles.edit') }}"><x-icon name="pencil" :size="14" />Editar perfil</a>
    @elseif($friendshipStatus || auth()->user()->can('befriend', $profile))
        @php($friendLabel = match($friendshipStatus) { 'pending' => 'Cancelar solicitação', 'accepted' => 'Remover amizade', default => 'Solicitar amizade' })
        <form method="POST" action="{{ route('profiles.friend', $profile) }}">@csrf @method('PATCH')<button class="ui-btn ui-btn-outline ui-btn-sm"><x-icon name="{{ $friendshipStatus ? 'users' : 'user-plus' }}" :size="14" />{{ $friendLabel }}</button></form>
    @endif
    </div>
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
        <div class="history-row"><div><strong><x-profile-link :of="$requester">{{ $requester->playerProfile?->avatar ? $requester->playerProfile->avatar.' ' : '' }}{{ $requester->playerProfile?->nickname ?? $requester->name }}</x-profile-link></strong><small>quer ser seu amigo no ManaLeague</small></div><div class="deck-actions"><form method="POST" action="{{ route('friends.accept', $requester) }}">@csrf @method('PATCH')<button class="btn btn-small btn-primary">Aceitar</button></form><form method="POST" action="{{ route('friends.reject', $requester) }}">@csrf @method('DELETE')<button class="btn btn-small btn-ghost">Recusar</button></form></div></div>
    @endforeach
</section>
@endif








<div class="stat-grid">
    <article class="card stat-card"><b>{{ $summary['wins'] }}–{{ $summary['losses'] }}@if($summary['draws'])–{{ $summary['draws'] }}@endif</b><span>Vitórias / derrotas</span></article>
    <article class="card stat-card"><b>{{ $summary['games_for'] }}–{{ $summary['games_against'] }}</b><span>Games</span></article>
    <article class="card stat-card"><b>{{ $summary['winrate'] }}%</b><span>Aproveitamento</span></article>
    <article class="card stat-card"><b>{{ $summary['best_streak'] }}</b><span>Maior sequência</span></article>
</div>

@php($headToHead ??= null)
@if($headToHead && ($headToHead['played'] || $friendshipStatus === 'accepted'))
<section class="card section-card head-to-head" aria-labelledby="h2h-title">
    <span class="eyebrow">Confronto direto</span><h2 id="h2h-title">Você × {{ $profile->nickname }}</h2>
    @if($headToHead['played'])
        @php($last = $headToHead['last'])
        <div class="h2h-record">
            <div><b>{{ $headToHead['wins'] }}</b><span>{{ $headToHead['wins'] === 1 ? 'Vitória' : 'Vitórias' }}</span></div>
            <div><b>{{ $headToHead['losses'] }}</b><span>{{ $headToHead['losses'] === 1 ? 'Derrota' : 'Derrotas' }}</span></div>
            @if($headToHead['draws'])<div><b>{{ $headToHead['draws'] }}</b><span>{{ $headToHead['draws'] === 1 ? 'Empate' : 'Empates' }}</span></div>@endif
        </div>
        <p class="h2h-last">{{ $headToHead['played'] }} {{ $headToHead['played'] === 1 ? 'partida' : 'partidas' }} · última: {{ $last['kind'] }}, {{ ['win' => 'você venceu', 'loss' => 'você perdeu', 'draw' => 'empate'][$last['result']] }} por {{ $last['me'] }}×{{ $last['them'] }} em {{ $last['date']->timezone(config('app.timezone'))->format('d/m/Y') }}</p>
    @else
        <x-ui.empty icon="swords" title="Vocês ainda não se enfrentaram" text="Chame para um desafio e comece a rivalidade." :href="route('challenges.index', ['opponent' => $profile->id])" action="Desafiar" />
    @endif
</section>
@endif


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
            <div class="tx-row"><span class="delta {{ $tx->delta >= 0 ? 'up' : 'down' }}">{{ $tx->delta >= 0 ? '+' : '' }}{{ $tx->delta }}</span><div>@if($tx->event_type === 'correction')<strong>Correção da moderação</strong><small>{{ $tx->note }}</small>@else<strong>@if($isOwner)<a href="{{ route('ranked.matches.show', $tx->ranked_match_id) }}">@endif{{ $tx->rankedMatch?->playerOne?->displayName() }} {{ $tx->rankedMatch?->score_one }}×{{ $tx->rankedMatch?->score_two }} {{ $tx->rankedMatch?->playerTwo?->displayName() }}@if($isOwner)</a>@endif</strong><small>{{ $tx->format }} · {{ $tx->rating_before }} → {{ $tx->rating_after }}</small>@endif</div><time datetime="{{ $tx->effective_at->toIso8601String() }}">{{ $tx->effective_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</time></div>
        @endforeach
    @endif
    @if($ranked['snapshots']->isNotEmpty())
        <h3 class="achievement-category">Temporadas encerradas</h3>
        @foreach($ranked['snapshots'] as $snap)<p class="muted"><strong>{{ $snap->season->name }}</strong> · {{ $snap->format }}: {{ $snap->tier }} ({{ $snap->final_rating }})@if($snap->rank) · {{ $snap->rank }}º lugar @endif</p>@endforeach
    @endif
</section>
@endif
@php($filledSlots = collect($showcase['slots'])->filter())
@if($isOwner || $filledSlots->isNotEmpty())
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/hub.css') }}">@endpush
<section class="card section-card deck-showcase" id="vitrine">
    <div class="section-heading"><div><span class="eyebrow">Arsenal</span><h2>Vitrine de decks</h2></div>@if($isOwner)<a class="btn btn-small btn-secondary" href="{{ route('decks.index') }}">Gerenciar decks</a>@endif</div>
    @if($isOwner && $filledSlots->isEmpty())
        <p class="muted small-note">Escolha até 3 decks para mostrar no seu perfil. Enquanto a vitrine estiver vazia, seus amigos veem o deck principal e os próximos decks visíveis.</p>
    @endif

    <div class="showcase-slots">
        @foreach($showcase['slots'] as $slot => $d)
            @if($d)
                <div class="showcase-slot">
                    @include('decks.partials.deck-card', ['d' => $d, 'manage' => false])
                    @if($isOwner)
                        <div class="ui-dropdown showcase-slot-menu">
                            <button type="button" class="showcase-menu-btn" data-menu-button aria-expanded="false" aria-controls="showcase-menu-{{ $slot }}" aria-label="Opções do espaço {{ $slot }} da vitrine"><x-icon name="ellipsis" /></button>
                            <div class="ui-menu ui-menu-end" id="showcase-menu-{{ $slot }}" hidden>
                                <button type="button" class="ui-menu-item" data-showcase-pick="{{ $slot }}"><x-icon name="repeat" />Trocar deck</button>
                                <form method="POST" action="{{ route('showcase.destroy', $slot) }}">@csrf @method('DELETE')<button class="ui-menu-item"><x-icon name="x" />Tirar da vitrine</button></form>
                            </div>
                        </div>
                    @endif
                </div>
            @elseif($isOwner)
                <button type="button" class="showcase-empty" data-showcase-pick="{{ $slot }}" aria-label="Adicionar deck ao espaço {{ $slot }} da vitrine">
                    <span class="showcase-empty-icon"><x-icon name="plus" :size="20" /></span>
                    <strong>Adicionar deck</strong><small>à vitrine</small>
                </button>
            @endif
        @endforeach
    </div>

    @if($isOwner)
        <a class="ui-link deck-showcase-more" href="{{ route('decks.index') }}">Ver todos os {{ $showcase['total'] }} {{ $showcase['total'] === 1 ? 'deck' : 'decks' }}<x-icon name="arrow-right" :size="14" /></a>
    @elseif($showcase['others']->isNotEmpty())
        <details class="deck-showcase-more">
            <summary>Ver mais {{ $showcase['others']->count() }} {{ $showcase['others']->count() === 1 ? 'deck' : 'decks' }}</summary>
            <div class="deck-cards">@foreach($showcase['others'] as $d)@include('decks.partials.deck-card', ['d' => $d, 'manage' => false])@endforeach</div>
        </details>
    @endif
</section>

@if($isOwner)
<dialog class="showcase-picker" id="showcase-picker" aria-labelledby="showcase-picker-title">
    <form method="POST" action="{{ route('showcase.update', 1) }}" class="showcase-picker-inner" data-showcase-form>@csrf @method('PUT')
        <header class="showcase-picker-head">
            <h2 id="showcase-picker-title">Escolher deck para a vitrine</h2>
            <button type="button" class="ui-icon-btn" data-showcase-close aria-label="Fechar"><x-icon name="x" /></button>
        </header>
        @if($showcase['pickable']->count() > 4)
            <label class="hub-search showcase-picker-search"><x-icon name="search" /><input type="search" placeholder="Buscar deck…" aria-label="Buscar deck" data-showcase-search></label>
        @endif
        <div class="showcase-picker-list">
            @forelse($showcase['pickable'] as $d)
                @php($pick = $d['deck'])
                <div class="showcase-pick {{ $pick->is_visible_to_friends ? '' : 'is-disabled' }}" data-name="{{ mb_strtolower($pick->name) }}">
                    <span class="showcase-pick-art" @if($d['cover']) style="--art: url('{{ $d['cover'] }}')" @endif aria-hidden="true"></span>
                    <span class="showcase-pick-info">
                        <strong>{{ $pick->name }}</strong>
                        <small>{{ $pick->format ?: 'Formato livre' }} · {{ $d['cards'] }} {{ $d['cards'] === 1 ? 'carta' : 'cartas' }} @include('partials.color-pips', ['colors' => $pick->colors])</small>
                    </span>
                    @if(! $pick->is_visible_to_friends)
                        <span class="showcase-pick-note"><x-icon name="lock" :size="12" />Privado · <a href="{{ route('decks.edit', $pick) }}">tornar visível</a></span>
                    @else
                        @if($pick->showcase_position)<span class="showcase-pick-note">Na vitrine (espaço {{ $pick->showcase_position }})</span>@endif
                        <button class="ui-btn ui-btn-outline ui-btn-sm" name="deck_id" value="{{ $pick->id }}">{{ $pick->showcase_position ? 'Mover para cá' : 'Escolher' }}</button>
                    @endif
                </div>
            @empty
                <p class="muted">Você ainda não tem decks. <a href="{{ route('decks.index') }}#novo-deck">Criar o primeiro</a></p>
            @endforelse
            <p class="muted small showcase-picker-empty" hidden>Nenhum deck com esse nome.</p>
        </div>
    </form>
</dialog>
<script src="{{ \App\Support\VersionedAsset::url('js/showcase.js') }}" defer></script>
@endif
@endif

<div class="profile-columns">
    <section class="card section-card"><span class="eyebrow">Arsenal utilizado</span><h2>Decks nos torneios</h2>
        @forelse($summary['decks'] as $deck)<div class="history-row"><div><strong>{{ $deck['name'] }}</strong><small>@if($deck['colors'])@include('partials.color-pips', ['colors' => $deck['colors']])@else Cores não informadas @endif</small></div><b>{{ $deck['wins'] }}W — {{ $deck['losses'] }}L</b></div>@empty<p class="muted">Nenhum deck registrado.</p>@endforelse
    </section>
    <section class="card section-card"><span class="eyebrow">Histórico</span><h2>Campeonatos</h2>
        @forelse($summary['tournaments'] as $tournament)<a class="history-row" href="{{ route('arena.public', $tournament->public_slug) }}"><div><strong>{{ $tournament->name }}</strong><small>{{ $tournament->format }} · {{ $tournament->tournament_date?->format('d/m/Y') }}</small></div><span>→</span></a>@empty<p class="muted">Nenhum torneio disputado.</p>@endforelse
    </section>
</div>

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
    @if(auth()->user()->isAdmin() && $profile->account_user_id)
        @php($isBuilder = $achievements->firstWhere('key', 'builder')['unlocked'] ?? false)
        <form method="POST" action="{{ route('profiles.builder', $profile) }}" class="admin-grant">@csrf
            @if($isBuilder)<input type="hidden" name="revoke" value="1">@endif
            <button class="btn btn-small {{ $isBuilder ? 'btn-ghost' : 'btn-secondary' }}">🛠️ {{ $isBuilder ? 'Remover Construtor da Liga' : 'Conceder Construtor da Liga' }}</button>
            <small class="muted">Só admins veem isto.</small>
        </form>
    @endif
</section>




@endsection
