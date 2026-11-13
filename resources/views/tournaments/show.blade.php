@extends('layouts.app')
{{-- Tela ao vivo: atualiza sozinha quando algo muda (live-refresh.js). --}}
{{-- A página muda conforme a fase: preparação (inscrições), em andamento (rodada atual) e encerrado (campeão). --}}
@section('live-scope', 'tournament:'.$tournament->id)
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/life-chart.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/life-chart.js') }}"></script>@endpush
@section('content')
@php
    $canManage = auth()->user()->can('update', $tournament);
    $inSetup = $tournament->status === 'setup';
    $running = in_array($tournament->status, ['league', 'finals'], true);
    $finished = $tournament->status === 'finished';
    $statusLabel = match ($tournament->status) { 'setup' => 'Preparação', 'league' => 'Em andamento', 'finals' => 'Finais', 'finished' => 'Encerrado', default => $tournament->status };
    $systemLabel = \App\Models\Tournament::SYSTEMS[$tournament->system] ?? $tournament->system;
    $playersCount = $tournament->players->count();
    $present = $tournament->players->whereNotNull('checked_in_at')->count();
    $leaguePending = $tournament->matches->where('stage', 'league')->where('status', 'pending')->count();
    $leagueTotal = $tournament->matches->where('stage', 'league')->where('status', '!=', 'bye')->count();
    $showLeagueProgress = $tournament->status === 'league' && $tournament->system === 'round_robin' && $leagueTotal > 0;

    // Rodada atual: a primeira com partida pendente (no todos contra todos as rodadas já existem desde o início).
    $currentRound = $rounds->keys()->first(fn ($round) => $rounds[$round]->contains('status', 'pending')) ?? $rounds->keys()->last();
    $currentMatches = $currentRound !== null ? $rounds[$currentRound] : collect();
    $currentPending = $currentMatches->where('status', 'pending')->count();
    $isFinalsRound = $currentMatches->isNotEmpty() && $currentMatches->every(fn ($m) => in_array($m->stage, ['final', 'third_place'], true));
    $roundLimit = in_array($tournament->system, ['swiss', 'commander_pods'], true) ? $tournament->round_limit : null;
    $systemHelp = ['swiss' => 'Pareamento por pontuação, sem revanches quando possível.', 'single_elimination' => 'Uma derrota elimina.', 'double_elimination' => 'Duas derrotas eliminam, com final de desempate se necessário.', 'commander_pods' => 'Informe a posição de cada jogador na mesa (1º ao último).'][$tournament->system] ?? null;
    $timerRunning = $tournament->roundTimerRunning();
    $pendingSubmissions = $canManage && ! $inSetup ? \App\Models\MatchResultSubmission::whereIn('status', ['pending', 'disputed'])->whereHas('gameMatch', fn ($q) => $q->where('tournament_id', $tournament->id))->count() : 0;

    $repeatOpen = $finished || request()->boolean('repetir') || old('copy_players') !== null;
    $otherRounds = $running ? $rounds->filter(fn ($matches, $round) => $round !== $currentRound) : $rounds;
    $repeat = $canManage ? $tournament->nextEditionDefaults() : null;
    $clubManage = $tournament->club_id && $tournament->club && auth()->user()->can('manage', $tournament->club);
@endphp
<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/competition.css') }}">
@if($running)<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/live.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/round-timer.js') }}"></script>@endif

<header class="arena-header tourney-header">
    <div>
        <a class="back-link" href="{{ route('tournaments.index') }}">← Todas as arenas</a>
        <div class="title-line"><span class="format-badge">{{ $tournament->format }}</span><span class="status status-{{ $tournament->status }}">{{ $statusLabel }}</span></div>
        <h1>{{ $tournament->name }}</h1>
        <p class="tourney-meta">
            <span>{{ $systemLabel }}</span>
            <span>{{ $tournament->tournament_date?->format('d/m/Y') ?? 'Data a definir' }}</span>
            <span>{{ $playersCount }} {{ $playersCount === 1 ? 'jogador' : 'jogadores' }}</span>
            @if($tournament->club_id && $tournament->club)<a href="{{ route('clubs.show', $tournament->club) }}">🛡️ {{ $tournament->club->name }}</a>@endif
        </p>
        @include('tournaments.partials.rules-note')
    </div>
    <div class="header-actions">
        @if($inSetup && $canManage)<a class="btn btn-secondary" href="{{ route('tournaments.edit', $tournament) }}">Editar torneio</a>@endif
        <a class="btn btn-ghost" target="_blank" rel="noopener" href="{{ route('arena.public', $tournament->public_slug) }}">Página pública ↗</a>
        <div class="ui-dropdown">
            <button type="button" class="btn btn-ghost" data-menu-button aria-expanded="false" aria-controls="tourney-menu">Mais <x-icon name="chevron-down" /></button>
            <div class="ui-menu ui-menu-end" id="tourney-menu" hidden>
                @if($inSetup && $canManage)<button type="button" class="ui-menu-item" data-dialog-open="tourney-share"><x-icon name="link" />Compartilhar</button>@endif
                <a class="ui-menu-item" target="_blank" rel="noopener" href="{{ route('arena.tv', $tournament->public_slug) }}"><x-icon name="layout-dashboard" />Modo TV ↗</a>
                @if($canManage)
                    <a class="ui-menu-item" href="{{ route('tournaments.show', [$tournament, 'repetir' => 1]) }}#repetir"><x-icon name="repeat" />Repetir torneio</a>
                    @if($clubManage)<form method="POST" action="{{ route('clubs.tournaments.unlink', [$tournament->club, $tournament]) }}" data-confirm="Tirar este torneio do clube? Ele sai do ranking do clube e volta a ser só do criador.">@csrf @method('DELETE')<button class="ui-menu-item"><x-icon name="shield" />Desvincular do clube</button></form>@endif
                    <form method="POST" action="{{ route('tournaments.destroy', $tournament) }}" data-confirm="Excluir o torneio e todos os resultados?">@csrf @method('DELETE')<button class="ui-menu-item is-danger"><x-icon name="trash" />Excluir torneio</button></form>
                @endif
            </div>
        </div>
    </div>
</header>

{{-- Compartilhar (menu Mais): link e QR de inscrição numa janela, fora da lista de jogadores --}}
@if($inSetup && $canManage)
<dialog class="ui-dialog tourney-share" id="tourney-share" aria-labelledby="tourney-share-title">
    <header class="ui-dialog-head">
        <h2 id="tourney-share-title">Compartilhar inscrições</h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar"><x-icon name="x" /></button>
    </header>
    <div class="ui-dialog-body">
        <img class="tourney-share-qr" src="{{ route('tournaments.qr.png', $tournament) }}" alt="QR de inscrição do torneio" width="240" height="240" loading="lazy">
        <p class="muted tourney-share-hint">Quem escanear o QR ou abrir o link se inscreve no torneio. Novos jogadores entram só por aqui.</p>
        <label for="invite-url">Link de inscrição</label>
        <div class="tourney-invite-row">
            <input id="invite-url" readonly value="{{ route('tournaments.invite.show', $tournament->invite_token) }}">
            <button type="button" class="ui-btn ui-btn-outline" data-copy="#invite-url">Copiar link</button>
        </div>
        <p class="tourney-share-actions"><a class="ui-btn ui-btn-ghost ui-btn-sm" href="{{ route('tournaments.qr.show', $tournament) }}">Exibir QR em tela cheia</a> <a class="ui-btn ui-btn-ghost ui-btn-sm" download="inscricoes.png" href="{{ route('tournaments.qr.png', $tournament) }}">Baixar PNG</a></p>
    </div>
    <footer class="ui-dialog-foot">
        <form method="POST" action="{{ route('tournaments.invite.regenerate', $tournament) }}" data-confirm="Gerar um novo convite? O link e o QR atuais deixam de funcionar.">@csrf<button class="ui-btn ui-btn-ghost ui-btn-sm">Gerar novo link</button></form>
        <small class="muted">O link e o QR atuais deixam de funcionar.</small>
    </footer>
</dialog>
@endif

@include('tournaments.partials.my-entry')

{{-- ENCERRADO: campeão e pódio num card só --}}
@if($finished)@include('tournaments.partials.champion-card')@endif

{{-- EM ANDAMENTO: a rodada atual, com cronômetro e o próximo passo --}}
@if($running && $currentRound !== null)
<section class="card section-card tourney-round" id="round-{{ $currentRound }}" aria-labelledby="tourney-round-title">
    <div class="tourney-round-head">
        <div>
            <span class="eyebrow">Rodada atual</span>
            <h2 id="tourney-round-title">@if($isFinalsRound)Final e 3º lugar @else Rodada {{ $currentRound }}@if($roundLimit) de {{ $roundLimit }}@endif @endif</h2>
            <p class="muted">{{ $currentPending ? $currentPending.' '.($currentPending === 1 ? 'partida pendente' : 'partidas pendentes') : 'Todas as partidas com resultado' }} · {{ $currentMatches->count() }} {{ $currentMatches->count() === 1 ? 'mesa' : 'mesas' }}@if($systemHelp) · {{ $systemHelp }}@endif</p>
        </div>
        @if($canManage)
        <div class="tourney-round-actions">
            <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('matches.submissions.queue', $tournament) }}">Resultados enviados @if($pendingSubmissions)<span class="nav-badge" aria-label="{{ $pendingSubmissions }} aguardando">{{ $pendingSubmissions }}</span>@endif</a>
            @if($tournament->status === 'league' && $tournament->system === 'round_robin')
                <form method="POST" action="{{ route('tournaments.finals', $tournament) }}">@csrf<button class="ui-btn ui-btn-primary ui-btn-sm" @disabled($leaguePending > 0) @if($leaguePending > 0) title="Falta resultado em {{ $leaguePending }} {{ $leaguePending === 1 ? 'partida' : 'partidas' }} da liga" @endif>Gerar final + 3º lugar</button></form>
            @elseif($tournament->status === 'league')
                <form method="POST" action="{{ route('tournaments.advance', $tournament) }}">@csrf<input type="hidden" name="round" value="{{ $tournament->matches->max('round') }}"><button class="ui-btn ui-btn-primary ui-btn-sm" @disabled($tournament->matches->contains('status', 'pending'))>Concluir rodada / avançar</button></form>
            @endif
        </div>
        @endif
    </div>
    @if($showLeagueProgress)
        <div class="tourney-progress">
            <p><span>Liga: <b>{{ $leagueTotal - $leaguePending }} de {{ $leagueTotal }}</b> partidas com resultado</span>@if($leaguePending)<span class="muted">{{ $leaguePending === 1 ? 'falta 1' : 'faltam '.$leaguePending }} para liberar a final</span>@else<span class="muted">liga completa: já dá para gerar a final</span>@endif</p>
            <div class="tourney-progress-bar" role="progressbar" aria-label="Partidas da liga com resultado" aria-valuemin="0" aria-valuemax="{{ $leagueTotal }}" aria-valuenow="{{ $leagueTotal - $leaguePending }}"><span style="width: {{ round(($leagueTotal - $leaguePending) * 100 / $leagueTotal) }}%"></span></div>
        </div>
    @endif
    @if($canManage)
        <div class="tourney-timer">
            <x-round-timer :ends-at="$timerRunning ? $tournament->round_ends_at : null" />
            <form method="POST" action="{{ route('tournaments.timer.start', $tournament) }}" class="tourney-timer-form">@csrf
                <label>Minutos<input type="number" name="minutes" min="5" max="180" value="{{ old('minutes', $tournament->round_minutes ?? \App\Http\Controllers\EventDayController::DEFAULT_ROUND_MINUTES) }}" required></label>
                <button class="ui-btn ui-btn-outline ui-btn-sm">{{ $timerRunning ? 'Reiniciar' : 'Iniciar cronômetro' }}</button>
            </form>
            @if($timerRunning)<form method="POST" action="{{ route('tournaments.timer.stop', $tournament) }}">@csrf @method('DELETE')<button class="ui-btn ui-btn-ghost ui-btn-sm">Parar</button></form>@endif
            <small class="muted">Aparece no placar público, no Modo TV e em Minhas partidas.</small>
        </div>
    @elseif($timerRunning)
        <x-round-timer :ends-at="$tournament->round_ends_at" />
    @endif
    <div class="match-grid tourney-matches">@foreach($currentMatches as $match)@include('tournaments.partials.competition-match', ['manage' => $canManage, 'live' => true, 'table' => $loop->iteration])@endforeach</div>
</section>
@endif

{{-- PREPARAÇÃO: inscrições (link, QR, presença e jogadores) e o botão de começar --}}
@if($inSetup)
<div class="tourney-setup @if($canManage) has-aside @endif">
    <section class="card section-card tourney-entries" aria-labelledby="tourney-entries-title">
        <div class="section-heading">
            <div><span class="eyebrow">Inscrições</span><h2 id="tourney-entries-title">Jogadores <span class="count">{{ $playersCount }}</span></h2></div>
            @if($playersCount)<span class="tourney-checkin" title="Presenças confirmadas">Check-in {{ $present }} de {{ $playersCount }}</span>@endif
        </div>
        @if($canManage)
            @if($present > 0 && $present < $playersCount)
                <div class="check-in-summary"><p class="muted">{{ $playersCount - $present }} {{ $playersCount - $present === 1 ? 'inscrito ainda não fez' : 'inscritos ainda não fizeram' }} check-in.</p><form method="POST" action="{{ route('tournaments.absent.destroy', $tournament) }}" data-confirm="Remover {{ $playersCount - $present }} {{ $playersCount - $present === 1 ? 'inscrito sem check-in' : 'inscritos sem check-in' }}? Eles saem do torneio.">@csrf @method('DELETE')<button class="btn btn-small btn-ghost">Remover ausentes</button></form></div>
            @endif
        @endif
        <div class="player-list">
        @forelse($tournament->players as $player)
            <div class="player-row">
                <div><strong>@if($player->profile)<a href="{{ route('profiles.show', $player->profile) }}">{{ $player->profile->avatar ?: '◉' }} {{ $player->name }}</a>@else{{ $player->name }}@endif</strong><small>{{ $player->deckLabel() ?: 'Deck não informado' }} @if($player->deckColors()) · {{ $player->deckColors() }} @endif</small>@if($player->needsDeck($tournament))<span class="ban-tag is-neutral" title="O deck não está cadastrado na conta da pessoa. A presença só pode ser marcada depois que ela escolher um deck salvo pelo link de inscrição.">Sem deck</span>@endif @if(isset($bannedDecks[$player->deck_id]))<span class="ban-tag" title="O deck tem {{ \App\Services\Decks\BanListService::blockedLabel($tournament->format) }} no {{ $tournament->format }}. A presença só pode ser marcada depois que a pessoa trocar as cartas ou o deck.">Deck inválido</span>@endif</div>
                @if($canManage)
                <div class="player-actions"><form method="POST" action="{{ route('tournaments.players.check-in', [$tournament, $player]) }}">@csrf @method('PATCH')@if($player->checked_in_at)<button class="btn btn-small btn-secondary check-in-on" aria-label="Desmarcar presença de {{ $player->name }}">✓ Presente</button>@else<button class="btn btn-small btn-ghost" aria-label="Marcar presença de {{ $player->name }}">Marcar presença</button>@endif</form><form method="POST" action="{{ route('players.destroy', [$tournament, $player]) }}">@csrf @method('DELETE')<button class="icon-button" aria-label="Remover {{ $player->name }}">×</button></form></div>
                @elseif($player->checked_in_at)
                <span class="tourney-present">✓ Presente</span>
                @endif
            </div>
        @empty
            <div class="tourney-empty">
                <p class="muted">A mesa ainda está vazia. Envie o link para a galera.</p>
                @if($canManage)<button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-dialog-open="tourney-share"><x-icon name="link" />Compartilhar</button>@endif
            </div>
        @endforelse
        </div>
        @if($canManage && $friends->isNotEmpty())
            <details class="tourney-friends">
                <summary>Adicionar amigos salvos <span class="count">{{ $friends->count() }}</span></summary>
                <div class="friend-picker">
                @foreach($friends as $friend)<form method="POST" action="{{ route('tournaments.friends.store', [$tournament, $friend]) }}">@csrf<div><strong><x-profile-link :of="$friend">{{ $friend->avatar ?: '★' }} {{ $friend->nickname }}</x-profile-link></strong><small>{{ $friend->preferred_deck_name ?: 'Deck não informado' }}</small></div><button class="btn btn-small btn-secondary">Adicionar</button></form>@endforeach
                </div>
            </details>
        @endif
    </section>
    @if($canManage)
    <aside class="card start-card">
        <span class="eyebrow">Próximo passo</span><h2>Começar torneio</h2>
        <p>Com 4 ou mais jogadores, os confrontos seguem o sistema escolhido ({{ $systemLabel }}). Os decks são congelados no início.</p>
        <form method="POST" action="{{ route('tournaments.start', $tournament) }}">@csrf<button class="btn btn-primary btn-block" @disabled($playersCount < 4)>Começar torneio</button></form>
        <small>{{ $playersCount < 4 ? 'Faltam '.(4 - $playersCount).' '.(4 - $playersCount === 1 ? 'jogador' : 'jogadores').'.' : 'Tudo pronto para começar.' }}</small>
    </aside>
    @endif
</div>
@endif

{{-- Classificação: só depois que o torneio começa (antes disso ela repetiria a lista de jogadores, zerada) --}}
@unless($inSetup)
<section class="card section-card" aria-labelledby="standings-title">
    <div class="section-heading"><div><h2 id="standings-title">{{ $finished ? 'Classificação final' : 'Classificação' }}</h2></div></div>
    @include('partials.standings')
</section>
@endunless

{{-- Rodadas: no andamento, as outras além da atual; no encerrado, todas. Fechadas para não alongar a página. --}}
@if($otherRounds->isNotEmpty())
    @include('tournaments.partials.round-list', [
        'rounds' => $otherRounds, 'currentRound' => $running ? $currentRound : null, 'manage' => $canManage,
        'title' => $running ? 'Outras rodadas' : 'Rodadas',
    ])
@endif

@if($canManage)
<details class="card tourney-repeat" id="repetir" @if($repeatOpen) open @endif>
    <summary><span><span class="eyebrow">Liga toda semana?</span><strong>Repetir torneio</strong></span></summary>
    <p class="muted">Cria um torneio novo com o mesmo formato, sistema e regras{{ $tournament->club_id ? ', no mesmo clube' : '' }}. Ele começa em preparação, com links de inscrição novos.</p>
    <form method="POST" action="{{ route('tournaments.repeat', $tournament) }}" class="repeat-form">@csrf
        <label>Nome<input name="name" value="{{ old('name', $repeat['name']) }}" maxlength="120" required></label>
        <label>Data<input type="date" name="tournament_date" value="{{ old('tournament_date', $repeat['date']) }}" required></label>
        <input type="hidden" name="copy_players" value="0">
        <label class="check-label"><input type="checkbox" name="copy_players" value="1" @checked(old('copy_players', true))> Trazer os mesmos {{ $playersCount }} jogadores (com os decks)</label>
        <button class="btn btn-secondary">Criar próximo torneio</button>
    </form>
</details>
@endif
@if(! empty($lifeLogs))@include('life.partials.dialog')@endif
@endsection
