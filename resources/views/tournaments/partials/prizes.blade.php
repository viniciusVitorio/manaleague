{{-- Premiação do torneio (página do organizador e página pública). Antes de encerrar mostra o que está em jogo; depois, quem ganhou. --}}
{{-- Um cartão compacto por colocação, lado a lado (no celular, um embaixo do outro). --}}
{{-- Espera $tournament, $prizes (PrizeService::forTournament) e $entry (inscrição de quem vê, ou null). $manage liga as ações do organizador. --}}
{{-- Entrega de booster é assunto do organizador e de quem ganhou: os outros só veem o nome do vencedor. --}}
@php
    $manage = $manage ?? false;
    $prizesDone = $tournament->status === \App\Models\Tournament::STATUS_FINISHED;
    $prizesEditable = $manage && ! $prizesDone;
    $ownEntryId = $entry?->id;
@endphp
@if($prizes->isNotEmpty() || $prizesEditable)
@once
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/prizes.css') }}">@endpush
@endonce
<section class="card section-card prize-board" id="premiacao" aria-labelledby="prize-board-title">
    <div class="section-heading">
        <div><h2 id="prize-board-title">Premiação</h2></div>
        @if($prizesEditable)<a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('tournaments.prizes.edit', $tournament) }}"><x-icon name="pencil" />{{ $prizes->isEmpty() ? 'Definir premiação' : 'Editar premiação' }}</a>@endif
    </div>
    @if($prizes->isEmpty())
        <p class="muted prize-board-empty">Nenhum prêmio definido. Você pode oferecer boosters e um troféu ou medalha por colocação: quem ganhar recebe sozinho quando o {{ $tournament->kindNoun() }} encerrar.</p>
    @else
    <ol class="prize-places">
        @foreach($prizes as $place)
            @php
                $everyone = $place['placement'] === \App\Models\TournamentPrize::PARTICIPATION;
                $winner = $prizesDone && ! $everyone ? $place['prizes']->first()->awards->first()?->player : null;
                $received = $prizesDone && $everyone ? $place['prizes']->first()->awards->count() : 0;
            @endphp
            <li class="prize-place is-{{ $place['tone'] }}">
                <div class="prize-place-head">
                    <span class="prize-place-label">{{ $place['label'] }}</span>
                    @if($winner)<span class="prize-place-who"><x-profile-link :of="$winner">{{ $winner->name }}</x-profile-link></span>
                    @elseif($received)<span class="prize-place-who">{{ $received }} {{ $received === 1 ? 'inscrito' : 'inscritos' }}</span>
                    @elseif($everyone)<span class="prize-place-who">Todos os inscritos</span>@endif
                </div>
                <ul class="prize-items">
                    @foreach($place['prizes'] as $prize)
                        @php
                            $awards = $prizesDone ? $prize->awards : collect();
                            $ownAward = $ownEntryId ? $awards->firstWhere('player_id', $ownEntryId) : null;
                            $delivered = $awards->whereNotNull('redeemed_at')->count();
                        @endphp
                        <li class="prize-item">
                            @include('partials.prize-icon', ['prize' => $prize, 'size' => 18])
                            <div class="prize-item-text">
                                <strong>{{ $prize->title() }}</strong>
                                <small>{{ $prize->isTrophy() ? 'Colecionável do perfil' : 'Prêmio físico' }}</small>
                            </div>
                            @if(! $prize->isTrophy() && $awards->isNotEmpty())
                                @if($manage && $everyone)
                                    <details class="prize-deliveries">
                                        <summary>Entregas <span class="count">{{ $delivered }} de {{ $awards->count() }}</span></summary>
                                        <ul>
                                            @foreach($awards->sortBy(fn ($award) => $award->player->name) as $award)
                                                <li><span>{{ $award->player->name }}</span>@include('tournaments.partials.prize-delivery', ['award' => $award, 'manage' => true])</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @elseif($manage)
                                    @include('tournaments.partials.prize-delivery', ['award' => $awards->first(), 'manage' => true])
                                @elseif($ownAward)
                                    @include('tournaments.partials.prize-delivery', ['award' => $ownAward, 'manage' => false])
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            </li>
        @endforeach
    </ol>
    @endif
</section>
@endif
