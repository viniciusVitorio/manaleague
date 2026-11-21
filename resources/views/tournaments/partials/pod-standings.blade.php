<div class="table-wrap"><table class="standings pod-elimination-standings">
<thead><tr><th>Posição</th><th>Jogador</th><th>Mesas confirmadas</th><th>Situação</th></tr></thead><tbody>
@foreach($ranking as $row)
<tr><td>{{ $row['rank'] }}º</td><td><x-profile-link :of="$row['player']">{{ $row['player']->displayName() }}</x-profile-link></td><td>{{ $row['played'] }}</td><td>
@php
    $podLabel = ['active' => 'Em disputa', 'qualified' => 'Classificado', 'eliminated' => 'Eliminado', 'finalist' => 'Finalista', 'champion' => 'Campeão da final'][$row['pod_status']];
@endphp
<x-ui.badge :tone="$row['pod_status'] === 'champion' ? 'success' : 'neutral'">{{ $podLabel }}</x-ui.badge>
</td></tr>
@endforeach
</tbody></table></div>
<p class="table-note">Posições compartilhadas preservam empates e eliminações na mesma fase. Sem consolação, não há desempate entre eliminados de mesas diferentes. Não pontua ELO/PDL nem estatísticas de partidas 1v1.</p>
