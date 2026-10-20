<section class="card competition-status">
<h2>{{ \App\Models\Tournament::SYSTEMS[$tournament->system] }}</h2>
@if($tournament->system!=='round_robin')
<p>Rodada {{ $tournament->matches->max('round') ?? 0 }}@if(in_array($tournament->system,['swiss','commander_pods'])) / {{ $tournament->round_limit ?? 'automático' }}@endif. Resultados ficam travados ao avançar. {{ ['swiss'=>'Suíço: pareamento por pontuação, sem revanches quando possível.','single_elimination'=>'Eliminação simples: uma derrota elimina.','double_elimination'=>'Dupla eliminação: duas derrotas eliminam, com final de desempate se necessário.','commander_pods'=>'Pods Commander: informe a posição de cada jogador na mesa (1º ao último).'][$tournament->system] ?? '' }}</p>
@if($tournament->status==='league')<form method="POST" action="{{ route('tournaments.advance',$tournament) }}">@csrf<input type="hidden" name="round" value="{{ $tournament->matches->max('round') }}"><button class="btn btn-primary" @disabled($tournament->matches->contains('status','pending'))>Concluir rodada / avançar</button></form>@endif
@if($tournament->status==='finished' && in_array($tournament->system,['swiss','commander_pods']))<p><strong>Campeão: {{ $ranking->first()['player']->name ?? '—' }}</strong></p>@endif
@endif
</section>
