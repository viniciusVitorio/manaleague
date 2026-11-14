@extends('layouts.app')
{{-- Tela ao vivo: atualiza sozinha quando algo muda (live-refresh.js). --}}
@section('live-scope', 'me')
@push('head')<script defer src="{{ \App\Support\VersionedAsset::url('js/score-step.js') }}"></script>@endpush
@section('content')
{{-- Desafios: o que está em andamento (com as ações no próprio cartão) e as rivalidades. O histórico das séries fica em
     Minhas partidas, junto com torneios e Ranked. --}}
<header class="page-head">
    <div><span class="eyebrow">Mesa casual</span><h1>Desafios</h1><p>Séries 1v1 contra amigos, casuais ou valendo ranque. O placar só conta depois que os dois confirmam. O histórico fica em <a class="challenge-history-link" href="{{ route('matches.mine', ['tipo' => 'desafios']) }}">Minhas partidas</a>.</p></div>
    <div class="page-head-actions">
        <a class="ui-btn ui-btn-primary" href="{{ route('challenges.create') }}"><x-icon name="swords" />Desafiar amigo</a>
    </div>
</header>

@include('partials.confirm-all')

@if($open->isEmpty() && $rivals->isEmpty())
    <x-ui.card>
        @if($hasFriends)
            <x-ui.empty icon="swords" title="Nenhum desafio ainda." text="Chame um amigo para a primeira série. Quando os dois confirmarem o placar, a rivalidade aparece aqui." :href="route('challenges.create')" action="Desafiar amigo" />
        @else
            <x-ui.empty icon="user-plus" title="Nenhum desafio ainda." text="Desafios são entre amigos com conta. Adicione alguém para mandar o primeiro convite." :href="route('friends.index')" action="Encontrar amigos" />
        @endif
    </x-ui.card>
@else
    <x-ui.card title="Em andamento" icon="clock" :meta="$open->count() ? $open->count().' '.($open->count() === 1 ? 'desafio' : 'desafios') : null" class="mm-section challenge-section">
        @forelse($open as $row)
            @include('challenges.partials.open-challenge', ['row' => $row])
        @empty
            <p class="muted small-note">Nenhum desafio em andamento. Convites e séries esperando placar aparecem aqui.</p>
        @endforelse
    </x-ui.card>

    <x-ui.card title="Rivalidades" icon="users" :meta="$rivals->count() ? $rivals->count().' '.($rivals->count() === 1 ? 'rival' : 'rivais') : null" class="challenge-section" id="rivalidades">
        @forelse($rivals as $rival)
            @php($pct = $rival['played'] ? round($rival['wins'] / $rival['played'] * 100) : 0)
            <div class="ui-row rival-row">
                <x-profile-link :of="$rival['profile']" class="avatar-link" tabindex="-1" aria-hidden="true"><x-avatar :of="$rival['profile']" class="ui-avatar" aria-hidden="true" /></x-profile-link>
                <span class="ui-row-main"><span class="ui-row-title"><x-profile-link :of="$rival['profile']">{{ $rival['profile']->nickname }}</x-profile-link></span><span class="ui-row-sub">{{ $rival['played'] }} {{ $rival['played'] === 1 ? 'série' : 'séries' }}@if($rival['ranked']) · {{ $rival['ranked'] }} ranqueada{{ $rival['ranked'] > 1 ? 's' : '' }}@endif · {{ $pct }}% de vitórias</span></span>
                <span class="rival-bar" aria-hidden="true"><span style="width: {{ $pct }}%"></span></span>
                <span class="rival-record" aria-label="{{ $rival['wins'] }} vitórias, {{ $rival['losses'] }} derrotas{{ $rival['draws'] ? ' e '.$rival['draws'].' empates' : '' }}"><strong>{{ $rival['wins'] }}</strong>–{{ $rival['losses'] }}@if($rival['draws'])–{{ $rival['draws'] }}@endif</span>
                <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('challenges.create', ['opponent' => $rival['profile']->id]) }}">Revanche</a>
            </div>
        @empty
            <p class="muted small-note">Nenhuma rivalidade ainda. Quando você e um amigo confirmarem o placar de uma série, o retrospecto entre vocês aparece aqui.</p>
        @endforelse
        <details class="challenge-rules">
            <summary><x-icon name="info" :size="14" />Como funcionam as rivalidades</summary>
            <ul>
                <li>Cada desafio 1v1 vira uma <strong>série</strong> (melhor de 1, 3 ou 5). Quem ganha mais games leva a série; o placar fica como vitórias–derrotas (–empates, se houver).</li>
                <li>Só contam séries com <strong>placar confirmado pelos dois</strong>. Convites recusados, expirados ou contestados não entram.</li>
                <li>Valem desafios casuais e ranqueados. Partidas de torneio ficam nas estatísticas do perfil, não aqui.</li>
                <li>A rivalidade é só um retrospecto: ela <strong>não mexe</strong> no seu ELO nem nas estatísticas de torneio. Use <em>Revanche</em> para desafiar de novo.</li>
            </ul>
        </details>
    </x-ui.card>
@endif
@endsection
