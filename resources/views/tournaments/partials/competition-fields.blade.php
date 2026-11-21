<fieldset class="tournament-mode-options" role="radiogroup" aria-label="Sistema de competição">
    <legend>Sistema de competição</legend>
    @foreach(['commander' => ['commander_pods' => ['Pods Commander · por pontos', 'Some pontos em mesas de 3–4 a cada rodada.'], 'pod_elimination' => ['Eliminação por Pods', 'Os melhores de cada mesa avançam até a final.']], 'duel' => ['round_robin' => ['Todos contra todos', 'Todos se enfrentam ao longo das rodadas.'], 'swiss' => ['Suíço', 'Confrontos por campanha a cada rodada.'], 'single_elimination' => ['Eliminação simples', 'Perdeu o duelo, saiu da disputa.'], 'double_elimination' => ['Eliminação dupla', 'Duas derrotas eliminam o jogador.']]] as $group => $modes)
        @foreach($modes as $value => [$label, $description])
            @php($available = ($group === 'commander') === $commander)
            <label class="tournament-mode" data-system-choice data-format-group="{{ $group }}" @if(!$available) hidden @endif>
                <input type="radio" name="system" value="{{ $value }}" @disabled(!$available) @checked($available && $selectedSystem === $value) required>
                <span><strong>{{ $label }}</strong><small>{{ $description }}</small></span>
            </label>
        @endforeach
    @endforeach
    @if($legacySystem)
        <label class="tournament-mode" data-system-choice data-format-group="commander" data-legacy-system @if(!$commander) hidden @endif>
            <input type="radio" name="system" value="{{ $legacySystem }}" @disabled(!$commander) @checked($commander && $selectedSystem === $legacySystem) required>
            <span><strong>{{ \App\Models\Tournament::SYSTEMS[$legacySystem] }} · configuração atual</strong><small>Torneio 1v1 existente. Mantido até você escolher outro sistema.</small></span>
        </label>
    @endif
</fieldset>
<p class="tournament-form-note" data-multiplayer-notice @if(!$multiplayer) hidden @endif>40 de vida · mesas de 3–4 jogadores · sem ELO/PDL.</p>
<fieldset data-pod-elimination-settings @disabled(!$elimination) @if(!$elimination) hidden @endif>
    <label>Classificados por mesa<select name="pod_qualifiers">@foreach([1,2] as $qualifiers)<option value="{{ $qualifiers }}" @selected((int) old('pod_qualifiers', $tournament->pod_qualifiers ?? 2) === $qualifiers)>{{ $qualifiers === 1 ? 'Top 1' : 'Top 2' }}</option>@endforeach</select></label>
    <p class="tournament-form-note" data-pod-example>{{ (int) old('pod_qualifiers', $tournament->pod_qualifiers ?? 2) === 2 ? 'Exemplo com 8: 2 mesas de 4 → top 2 de cada → final de 4.' : 'Exemplo com 9: 3 mesas de 3 → top 1 de cada → final de 3.' }}</p>
    <p class="tournament-form-note">Mesas exatas de 3–4, sem BYE. O total de inscritos e o check-in serão conferidos no início.</p>
    @php($consolationAvailable = $elimination && (int) old('pod_qualifiers', $tournament->pod_qualifiers ?? 2) === 2 && (int) (old('max_players', $tournament->max_players ?? '') ?: 8) === 8)
    <fieldset data-consolation-setting @disabled(!$consolationAvailable) @if(!$consolationAvailable) hidden @endif>
        <input type="hidden" name="pod_consolation" value="0">
        <label class="ui-option ui-option-box"><span>Disputar 5º ao 8º<small>Consolação para 8 jogadores e top 2 por mesa.</small></span><input type="checkbox" class="ui-switch" name="pod_consolation" value="1" @checked(old('pod_consolation', $tournament->pod_consolation ?? false))></label>
    </fieldset>
</fieldset>
<fieldset data-round-settings @disabled(!$rounds) @if(!$rounds) hidden @endif>
    <label>Rodadas<input type="number" name="round_limit" min="1" max="20" value="{{ old('round_limit', $tournament->round_limit ?? '') }}" placeholder="Automático"><small>Vazio: log₂ do total de participantes, arredondado para cima.</small></label>
</fieldset>
<label>Limite de participantes <small data-player-limit-hint>Opcional, entre {{ $elimination ? 3 : 4 }} e 128</small><input type="number" name="max_players" min="{{ $elimination ? 3 : 4 }}" max="128" value="{{ old('max_players', $tournament->max_players ?? '') }}" placeholder="Sem limite"></label>
<div data-ranked-setting @if($multiplayer) hidden @endif>
    <input type="hidden" name="ranked" value="0">
    <label class="ui-option ui-option-box"><span>Torneio ranqueado<small>Partidas 1v1 pontuam ELO/PDL.</small></span><input class="ui-switch" type="checkbox" name="ranked" value="1" @disabled($multiplayer) @checked(!$multiplayer && old('ranked', $tournament->ranked ?? false))></label>
</div>
