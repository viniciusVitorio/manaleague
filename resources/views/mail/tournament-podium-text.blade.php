{{-- Versão em texto puro: nada aqui é interpretado como HTML, por isso sem escape. --}}
{!! $podium['medal'] !!} Você ficou em {!! $podium['placement_label'] !!}!

Parabéns, {!! $name !!}. Este foi o seu resultado em {!! $podium['tournament'] !!}.
{!! collect([$podium['format'], $podium['date'], $podium['players'].' '.($podium['players'] === 1 ? 'jogador' : 'jogadores')])->filter()->join(' · ') !!}

Colocação: {!! $podium['placement'] !!}º
Vitórias: {!! $podium['wins'] !!}
Derrotas: {!! $podium['losses'] !!}
@if($podium['draws'])
Empates: {!! $podium['draws'] !!}
@endif
Partidas: {!! $podium['played'] !!}
Taxa de vitória: {!! $podium['winrate'] !!}%
@if($podium['games'])
Games a favor e contra: {!! $podium['games'] !!}
@endif
@if($podium['prizes'])

Seus prêmios:
@foreach($podium['prizes'] as $prize)
- {!! $prize !!}
@endforeach
Ver meus prêmios: {!! $podium['prizes_url'] !!}
@endif

Ver resultado do torneio: {!! $podium['url'] !!}

Você recebeu este e-mail porque subiu ao pódio de um torneio no ManaLeague.
Para não receber mais, desligue Receber e-mails de atualizações nas preferências de notificações: {!! $podium['preferences_url'] !!}
