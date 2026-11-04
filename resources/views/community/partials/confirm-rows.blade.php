{{-- Linhas "Confirmar placar" do painel (torneio, ranked e desafio). Espera $toConfirm, $rankedConfirm e $confirmChallenges. --}}
@foreach($toConfirm as $submission)
    <li><a class="ui-row" href="{{ route('matches.mine') }}"><span class="ui-row-icon"><x-icon name="circle-check" /></span><span class="ui-row-main"><span class="ui-row-title">Confirmar placar</span><span class="ui-row-sub">{{ $submission->gameMatch->tournament->name }} · R{{ $submission->gameMatch->round }} · {{ $submission->gameMatch->playerOne?->displayName() }} {{ $submission->player_one_games }}×{{ $submission->player_two_games }} {{ $submission->gameMatch->playerTwo?->displayName() }}</span></span><x-icon name="chevron-right" class="ui-chevron" /></a></li>
@endforeach
@foreach($rankedConfirm as $rm)
    <li><a class="ui-row" href="{{ route('ranked.matches.show', $rm) }}"><span class="ui-row-icon"><x-icon name="chart" /></span><span class="ui-row-main"><span class="ui-row-title">Confirmar placar ranqueado</span><span class="ui-row-sub">{{ $rm->format }} · {{ $rm->playerOne->displayName() }} {{ $rm->score_one }}×{{ $rm->score_two }} {{ $rm->playerTwo->displayName() }}</span></span><x-icon name="chevron-right" class="ui-chevron" /></a></li>
@endforeach
@foreach($confirmChallenges as $challenge)
    <li><a class="ui-row" href="{{ route('challenges.show', $challenge) }}"><span class="ui-row-icon"><x-icon name="swords" /></span><span class="ui-row-main"><span class="ui-row-title">Confirmar placar do desafio</span><span class="ui-row-sub">{{ $challenge->challenger->displayName() }} × {{ $challenge->opponent->displayName() }} · {{ $challenge->format }} MD{{ $challenge->best_of }}</span></span><x-icon name="chevron-right" class="ui-chevron" /></a></li>
@endforeach
