@push('head')<script defer src="{{ \App\Support\VersionedAsset::url('js/pod-elimination-form.js') }}"></script>@endpush
<label class="check-row"><input type="checkbox" name="ranked" value="1" @checked(old('ranked',$tournament->ranked??false))> Torneio ranqueado <small>Somente partidas 1v1 pontuam ELO/PDL.</small></label>
<label>Sistema de competição<select name="system" required>@foreach(\App\Models\Tournament::SYSTEMS as $key=>$label)<option value="{{ $key }}" @if($key === 'pod_elimination') data-commander-only @disabled(old('format', $tournament->format ?? 'Pauper') !== 'Commander') @endif @selected(old('system',$tournament->system??'round_robin')===$key)>{{ $label }}</option>@endforeach</select></label>
<div data-pod-elimination-settings @if(old('system', $tournament->system ?? '') !== 'pod_elimination') hidden @endif>
    <h2>Eliminação por Pods</h2>
    <label>Classificados por mesa<select name="pod_qualifiers">@foreach([1,2] as $qualifiers)<option value="{{ $qualifiers }}" @selected((int) old('pod_qualifiers', $tournament->pod_qualifiers ?? 2) === $qualifiers)>{{ $qualifiers }}</option>@endforeach</select></label>
    <input type="hidden" name="pod_random_draw" value="0"><label class="check-label"><input type="checkbox" name="pod_random_draw" value="1" @checked(old('pod_random_draw', $tournament->pod_random_draw ?? true))> Sortear mesas aleatoriamente em cada fase</label>
    <label>Desempate<select name="pod_tiebreak"><option value="manual" @selected(old('pod_tiebreak', $tournament->pod_tiebreak ?? 'manual') === 'manual')>Decisão documentada do organizador</option><option value="playoff" @selected(old('pod_tiebreak', $tournament->pod_tiebreak ?? '') === 'playoff')>Partida de desempate documentada</option></select></label>
    <input type="hidden" name="pod_consolation" value="0"><label class="check-label"><input type="checkbox" name="pod_consolation" value="1" @checked(old('pod_consolation', $tournament->pod_consolation ?? false))> Consolação · 5º ao 8º (somente 8 jogadores, 2 classificados)</label>
    <p class="muted">Padrão: 8 jogadores, duas semifinais de 4, dois classificados por mesa e final de 4. Só estruturas exatas de mesas de 3–4 são aceitas. O total real de inscritos é validado de novo no início; todos devem fazer check-in. Não há BYE nem corte arbitrário. Sem sorteio, o nome define apenas os assentos, nunca desempates.</p>
</div>
<div data-legacy-competition-settings>
<label>Rodadas (Suíço / Pods)<input type="number" name="round_limit" min="1" max="20" value="{{ old('round_limit',$tournament->round_limit??'') }}" placeholder="Automático"><small>Vazio: log₂ de participantes, arredondado para cima. Eliminações avançam até restar um campeão.</small></label>
<p class="muted">Pods Commander mantém o sistema por pontos. Eliminação por Pods é uma modalidade adicional, com confirmação pelo organizador e avanço automático.</p>
</div>
