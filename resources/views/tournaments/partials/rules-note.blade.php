{{-- Resumo das regras do torneio (convite, página pública e página do organizador). Espera $tournament. --}}
<p class="tournament-rules">{{ $tournament->rulesLabel() }}</p>
@if($tournament->house_rules)<p class="house-rules"><strong>Regras da casa:</strong> {{ $tournament->house_rules }}</p>@endif
