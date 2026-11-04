@extends('layouts.app')
{{-- Tela ao vivo: atualiza sozinha quando algo muda (live-refresh.js). --}}
{{-- Placares que os jogadores mandaram pelo celular: primeiro o que precisa de decisão do organizador. --}}
@section('live-scope', 'tournament:'.$tournament->id)
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/competition.css') }}">@endpush
@section('content')
@php($statusInfo = ['pending' => ['Aguardando o adversário', 'warning'], 'disputed' => ['Contestado', 'danger'], 'confirmed' => ['Confirmado', 'success'], 'resolved' => ['Resolvido pelo organizador', 'info']])
@php($actionLabel = ['submitted' => 'enviou', 'confirm' => 'confirmou', 'dispute' => 'contestou', 'resolve' => 'decidiu'])
@php($when = fn ($date) => \Illuminate\Support\Carbon::parse($date)->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i'))
<header class="queue-header">
    <a class="back-link" href="{{ route('tournaments.show', $tournament) }}">← {{ $tournament->name }}</a>
    <h1>Resultados enviados</h1>
    <p class="muted">Placares que os jogadores mandaram pelo celular. O adversário confirma ou contesta; se houver contestação, você decide o placar.</p>
</header>

@forelse($submissions as $submission)
    @php($match = $submission->gameMatch)
    @php($info = $statusInfo[$submission->status] ?? [$submission->status, null])
    @php($open = in_array($submission->status, ['pending', 'disputed'], true))
    <article @class(['card', 'qsub', 'needs-you' => $submission->status === 'disputed'])>
        <header class="qsub-head">
            <span class="eyebrow">Rodada {{ $match->round }}</span>
            <span class="ui-badge @if($info[1]) ui-badge-{{ $info[1] }} @endif">{{ $info[0] }}</span>
        </header>
        <div class="qsub-score">
            <span><x-profile-link :of="$match->playerOne">{{ $match->playerOne?->displayName() }}</x-profile-link></span>
            <b>{{ $submission->player_one_games }} × {{ $submission->player_two_games }}</b>
            <span><x-profile-link :of="$match->playerTwo">{{ $match->playerTwo?->displayName() }}</x-profile-link></span>
        </div>
        <p class="qsub-meta muted">Enviado por {{ $people[$submission->submitted_by] ?? 'um jogador' }} em {{ $when($submission->created_at) }}</p>
        @if($submission->dispute_reason)<p class="qsub-reason"><strong>Motivo da contestação:</strong> {{ $submission->dispute_reason }}</p>@endif
        @if($submission->resolution_reason)<p class="qsub-reason"><strong>Decisão do organizador:</strong> {{ $submission->resolution_reason }}</p>@endif

        @if($open)
            <details class="qsub-resolve" @if($submission->status === 'disputed') open @endif>
                <summary>{{ $submission->status === 'disputed' ? 'Decidir o placar' : 'Decidir sem esperar o adversário' }}</summary>
                <form method="POST" action="{{ route('matches.submissions.resolve', $submission) }}" class="qsub-form">@csrf
                    <div class="qsub-form-scores">
                        <label>{{ $match->playerOne?->displayName() }}<input type="number" inputmode="numeric" name="player_one_games" min="0" max="3" value="{{ $submission->player_one_games }}" required></label>
                        <label>{{ $match->playerTwo?->displayName() }}<input type="number" inputmode="numeric" name="player_two_games" min="0" max="3" value="{{ $submission->player_two_games }}" required></label>
                    </div>
                    <label>Motivo da decisão<textarea name="reason" minlength="3" maxlength="1000" rows="2" required placeholder="Ex.: conferido com os dois jogadores na mesa"></textarea></label>
                    <button class="ui-btn ui-btn-primary ui-btn-sm">Resolver e publicar</button>
                </form>
            </details>
        @endif

        @php($history = $events->get($submission->id, collect()))
        @if($history->isNotEmpty())
            <details class="qsub-history">
                <summary>Histórico <span class="count">{{ $history->count() }}</span></summary>
                <ol>
                    @foreach($history as $event)
                        @php($data = json_decode($event->data, true) ?: [])
                        @php($score = $data['after'] ?? $data)
                        <li>
                            <time>{{ $when($event->created_at) }}</time>
                            <span><strong>{{ $people[$event->actor_id] ?? 'Conta #'.$event->actor_id }}</strong> {{ $actionLabel[$event->action] ?? $event->action }}@if(isset($score['player_one_games'], $score['player_two_games']) && $event->action !== 'dispute') {{ $score['player_one_games'] }} × {{ $score['player_two_games'] }}@endif @if(! empty($data['reason']))· “{{ $data['reason'] }}”@endif</span>
                        </li>
                    @endforeach
                </ol>
            </details>
        @endif
    </article>
@empty
    <x-ui.empty class="card" icon="inbox" title="Nenhum resultado enviado ainda" text="Quando um jogador mandar o placar pelo celular (em Minhas partidas), ele aparece aqui. Você também pode lançar o resultado direto no card da mesa." :href="route('tournaments.show', $tournament)" action="Voltar ao torneio" />
@endforelse
{{ $submissions->links() }}
@endsection
