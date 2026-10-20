@extends('layouts.app')
@section('content')
@php
    $me = auth()->user();
    $dashboard = app(\App\Services\Community\DashboardService::class);
    $activeOrganizing = $organizing->whereIn('status', ['setup', 'league', 'finals']);
    $activeTournaments = $playing->toBase()->pluck('id')->merge($activeOrganizing->toBase()->pluck('id'))->unique()->count();
    $challengeActions = $challenges->filter(fn ($c) => $dashboard->challengeNeedsMe($c, $me));
    $reviewQueue = $organizing->filter(fn ($t) => $awaitingReview->get($t->id));
    $tournamentRows = $playing->toBase()->map(fn ($t) => ['t' => $t, 'role' => 'Jogador', 'url' => route('arena.public', $t->public_slug)])
        ->merge($organizing->toBase()->map(fn ($t) => ['t' => $t, 'role' => 'Organizador', 'url' => route('tournaments.show', $t)]))
        ->unique(fn ($r) => $r['t']->id)->take(6);
    $tiers = config('ranked.enabled') ? app(\App\Services\Ranked\RankTierResolver::class) : null;
    $firstName = \Illuminate\Support\Str::of($me->playerProfile?->nickname ?: $me->name)->explode(' ')->first();
@endphp
<div class="dash">
    <header class="dash-head">
        <div>
            <h1>Meu painel</h1>
            <p>Olá, {{ $firstName }}. Acompanhe seus torneios, partidas e atividades no ManaLeague.</p>
        </div>
        <div class="dash-actions">
            <a class="ui-btn ui-btn-outline" href="{{ route('challenges.index') }}"><x-icon name="swords" />Desafiar amigo</a>
            <a class="ui-btn ui-btn-primary" href="{{ route('tournaments.create') }}"><x-icon name="plus" />Criar torneio</a>
        </div>
    </header>

    <section class="dash-stats" aria-label="Resumo">
        <x-ui.stat label="Torneios ativos" :value="$activeTournaments" icon="trophy" :href="route('tournaments.index')"
            :hint="$activeTournaments ? $playing->count().' jogando · '.$activeOrganizing->count().' organizando' : 'Nenhum em andamento'" />
        <x-ui.stat label="Partidas pendentes" :value="$pendingMatches" icon="target" :href="route('matches.mine')"
            :hint="$actionCount ? $actionCount.' '.($actionCount === 1 ? 'ação aguarda' : 'ações aguardam').' você' : 'Nada aguardando você'" />
        <x-ui.stat label="Notificações" :value="$unread" icon="bell" :href="route('notifications.index')"
            :hint="$unread ? ($unread === 1 ? 'não lida' : 'não lidas') : 'Tudo em dia'" />
        <x-ui.stat label="Taxa de vitória" :value="$stats['played'] ? $stats['winrate'].'%' : '—'" icon="chart" :href="route('profiles.index')"
            :hint="$stats['played'] ? $stats['wins'].'V · '.$stats['draws'].'E · '.$stats['losses'].'D em torneios' : 'Sem partidas de torneio ainda'" />
    </section>

    <div class="dash-main">
        <div class="dash-col">
            <x-ui.card class="next-match" title="Minha próxima partida" icon="swords">
                @if($nextMatch)
                    <div class="next-match-top">
                        <div>
                            <p class="next-match-eyebrow">{{ $nextMatch['kind'] === 'ranked' ? 'Ranked' : 'Torneio' }}</p>
                            <h3 class="next-match-title">{{ $nextMatch['context'] }}</h3>
                            <p class="next-match-sub">{{ $nextMatch['detail'] }}</p>
                        </div>
                        <x-ui.badge :tone="$nextMatch['status'] === 'disputed' ? 'danger' : 'warning'"><span class="ui-dot" aria-hidden="true"></span>{{ $nextMatch['statusLabel'] }}</x-ui.badge>
                    </div>
                    <div class="versus-row">
                        <div class="versus-side"><span class="versus-label">Você</span><span class="versus-name">{{ $nextMatch['me'] }}</span>@if($nextMatch['myDeck'])<span class="versus-deck">{{ $nextMatch['myDeck'] }}</span>@endif</div>
                        <span class="versus-mid" aria-hidden="true">VS</span>
                        <div class="versus-side"><span class="versus-label">{{ $nextMatch['opponentLabel'] }}</span><span class="versus-name">{{ $nextMatch['opponent'] }}</span>@if($nextMatch['opponentDeck'])<span class="versus-deck">{{ $nextMatch['opponentDeck'] }}</span>@endif</div>
                    </div>
                    <div class="next-match-actions">
                        <a class="ui-btn ui-btn-primary" href="{{ $nextMatch['url'] }}">{{ $nextMatch['action'] }}<x-icon name="arrow-right" /></a>
                        @if($nextMatch['secondaryUrl'])<a class="ui-btn ui-btn-outline" href="{{ $nextMatch['secondaryUrl'] }}">{{ $nextMatch['secondary'] }}</a>@endif
                    </div>
                @else
                    <x-ui.empty icon="circle-check" title="Nenhuma partida pendente." text="Entre num torneio pelo link do organizador ou chame alguém para jogar."
                        :href="route('challenges.index')" action="Desafiar amigo" />
                @endif
            </x-ui.card>

            @if($actionCount)
            <x-ui.card title="Precisa de você" icon="alert" :meta="$actionCount.' '.($actionCount === 1 ? 'pendência' : 'pendências')">
                <ul class="ui-list">
                    @foreach($rankedNeedsMe as $rm)
                        <li><a class="ui-row" href="{{ route('ranked.matches.show', $rm) }}"><span class="ui-row-icon"><x-icon name="chart" /></span><span class="ui-row-main"><span class="ui-row-title">{{ $rm->status === 'reported' ? 'Confirmar placar ranqueado' : 'Enviar resultado ranqueado' }}</span><span class="ui-row-sub">{{ $rm->format }} · {{ $rm->playerOne->nickname }} @if($rm->status === 'reported'){{ $rm->score_one }}×{{ $rm->score_two }}@else×@endif {{ $rm->playerTwo->nickname }}</span></span><x-icon name="chevron-right" class="ui-chevron" /></a></li>
                    @endforeach
                    @foreach($toConfirm as $submission)
                        <li><a class="ui-row" href="{{ route('matches.mine') }}"><span class="ui-row-icon"><x-icon name="circle-check" /></span><span class="ui-row-main"><span class="ui-row-title">Confirmar placar</span><span class="ui-row-sub">{{ $submission->gameMatch->tournament->name }} · R{{ $submission->gameMatch->round }} · {{ $submission->gameMatch->playerOne?->name }} {{ $submission->player_one_games }}×{{ $submission->player_two_games }} {{ $submission->gameMatch->playerTwo?->name }}</span></span><x-icon name="chevron-right" class="ui-chevron" /></a></li>
                    @endforeach
                    @foreach($toReport as $match)
                        <li><a class="ui-row" href="{{ route('matches.mine') }}"><span class="ui-row-icon"><x-icon name="target" /></span><span class="ui-row-main"><span class="ui-row-title">Enviar resultado</span><span class="ui-row-sub">{{ $match->tournament->name }} · R{{ $match->round }} · {{ $match->playerOne?->name }} × {{ $match->playerTwo?->name }}</span></span><x-icon name="chevron-right" class="ui-chevron" /></a></li>
                    @endforeach
                    @foreach($challengeActions as $challenge)
                        <li><a class="ui-row" href="{{ route('challenges.show', $challenge) }}"><span class="ui-row-icon"><x-icon name="swords" /></span><span class="ui-row-main"><span class="ui-row-title">{{ $challenge->status === 'pending' ? 'Desafio recebido' : ($challenge->casualMatch?->status === 'reported' ? 'Confirmar placar do desafio' : 'Desafio em andamento') }}</span><span class="ui-row-sub">{{ $challenge->challenger->nickname }} × {{ $challenge->opponent->nickname }} · {{ $challenge->format }} MD{{ $challenge->best_of }}</span></span><x-icon name="chevron-right" class="ui-chevron" /></a></li>
                    @endforeach
                    @foreach($reviewQueue as $tournament)
                        <li><a class="ui-row" href="{{ route('matches.submissions.queue', $tournament) }}"><span class="ui-row-icon"><x-icon name="flag" /></span><span class="ui-row-main"><span class="ui-row-title">Resultados para revisar</span><span class="ui-row-sub">{{ $tournament->name }} · {{ $awaitingReview->get($tournament->id) }} {{ $awaitingReview->get($tournament->id) === 1 ? 'envio' : 'envios' }}</span></span><x-icon name="chevron-right" class="ui-chevron" /></a></li>
                    @endforeach
                </ul>
            </x-ui.card>
            @endif
        </div>

        <div class="dash-col">
            <x-ui.card title="Notificações" icon="bell" :meta="$unread.' '.($unread === 1 ? 'não lida' : 'não lidas')" :href="route('notifications.index')">
                @if($recentNotifications->isEmpty())
                    <x-ui.empty icon="bell" title="Tudo em dia por aqui." text="Avisos de rodadas, placares e desafios aparecem aqui." />
                @else
                    <ul class="ui-list">
                        @foreach($recentNotifications as $notification)
                            <li><a @class(['ui-row', 'ui-row-unread' => ! $notification->read_at]) href="{{ $notification->url }}"><span class="ui-row-main"><span class="ui-row-title">{{ $notification->title }}</span><span class="ui-row-sub"><time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time></span></span></a></li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @if($tiers)
            <x-ui.card title="Ranque" icon="chart" :href="route('profiles.index')" link-label="Meu perfil">
                @forelse($rankedRatings as $rr)
                    @php($tier = $tiers->resolve($rr->rating))
                    <div class="rank-line"><span>{{ $rr->format }} <span class="ui-row-sub">· {{ $tier['division'] }}</span></span><b>{{ $rr->rating }}</b></div>
                @empty
                    <x-ui.empty icon="chart" title="Sem partidas ranqueadas." text="Jogue um desafio ranqueado: todos começam em Ferro II (600)." />
                @endforelse
            </x-ui.card>
            @endif
        </div>
    </div>

    <div class="dash-grid">
        <x-ui.card title="Torneios" icon="trophy" :href="route('tournaments.index')">
            @if($tournamentRows->isEmpty())
                <x-ui.empty icon="trophy" title="Nenhum torneio por enquanto." text="Crie um torneio ou peça o link ao organizador." :href="route('tournaments.create')" action="Criar" />
            @else
                <ul class="ui-list">
                    @foreach($tournamentRows as $row)
                        <li><a class="ui-row" href="{{ $row['url'] }}"><span class="ui-row-main"><span class="ui-row-title">{{ $row['t']->name }}</span><span class="ui-row-sub">{{ $row['role'] }} · {{ $row['t']->format }}@if(in_array($row['t']->status, ['league', 'finals']) && isset($row['t']->pending_matches_count)) · {{ $row['t']->pending_matches_count }} pendentes @endif</span></span><x-ui.status :status="$row['t']->status" /></a></li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Desafios" icon="swords" :href="route('challenges.index')">
            @if($challenges->isEmpty())
                <x-ui.empty icon="swords" title="Você não tem desafios em aberto." :href="route('challenges.index')" action="Desafiar" />
            @else
                <ul class="ui-list">
                    @foreach($challenges->take(5) as $challenge)
                        <li><a class="ui-row" href="{{ route('challenges.show', $challenge) }}"><span class="ui-row-main"><span class="ui-row-title">{{ $challenge->challenger->nickname }} × {{ $challenge->opponent->nickname }}</span><span class="ui-row-sub">@if($challenge->is_ranked)Ranqueada · @endif{{ $challenge->format }} · MD{{ $challenge->best_of }}</span></span><x-ui.status :status="$challenge->casualMatch?->status === 'reported' ? 'reported' : $challenge->status" /></a></li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Clubes" icon="shield" :href="route('clubs.index')">
            @if($clubs->isEmpty())
                <x-ui.empty icon="shield" title="Você ainda não participa de clubes." :href="route('clubs.index')" action="Ver clubes" />
            @else
                <ul class="ui-list">
                    @foreach($clubs->take(5) as $club)
                        <li><a class="ui-row" href="{{ route('clubs.show', $club) }}"><span class="ui-row-icon"><x-icon name="shield" /></span><span class="ui-row-main"><span class="ui-row-title">{{ $club->name }}</span><span class="ui-row-sub">{{ $club->privacy === 'private' ? 'Privado' : 'Público' }}</span></span><x-icon name="chevron-right" class="ui-chevron" /></a></li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="Atividade recente" icon="activity" :href="$activities->isNotEmpty() ? route('feed.index') : null">
        @if($activities->isEmpty())
            <x-ui.empty icon="activity" title="Suas atividades aparecerão aqui." text="Resultados confirmados, torneios encerrados e desafios dos seus grupos." />
        @else
            <ul class="ui-list">
                @foreach($activities as $activity)
                    <li><a class="ui-row" href="{{ app(\App\Services\Community\CommunityAccess::class)->link($me, $activity->subject_type, $activity->subject_id) }}"><span class="ui-row-icon"><x-icon name="activity" /></span><span class="ui-row-main"><span class="ui-row-title">{{ $activity->title }}</span><span class="ui-row-sub"><time datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->diffForHumans() }}</time></span></span></a></li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
@endsection
