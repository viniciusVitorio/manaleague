{{-- Formulário de evento (criar e editar), do "o quê" ao "como". Tipo, sistema e regras em cartões com explicação.
     O tipo não é gravado: sai do sistema. O JS (pod-elimination-form.js) mostra só os sistemas do tipo escolhido,
     libera a Eliminação por Pods só no Commander e mostra rodadas/opções de pods quando valem. Sem JS, o servidor já
     manda o estado certo e o formulário funciona. --}}
@push('head')<script defer src="{{ \App\Support\VersionedAsset::url('js/pod-elimination-form.js') }}"></script>@endpush
@php($event = $tournament ?? null)
@php($system = old('system', $event?->system ?? 'round_robin'))
@php($eventKind = old('kind', \App\Models\Tournament::kindOf($system)))
@php($format = old('format', $event?->format ?? 'Pauper'))
@php($pod = $system === 'pod_elimination')

<x-ui.card title="Sobre o evento" icon="calendar">
    <div class="event-form-grid">
        <label class="event-field-wide">Nome do evento<input name="name" value="{{ old('name', $event?->name ?? '') }}" maxlength="120" required @if(! $event) autofocus @endif placeholder="Ex.: Liga de Quinta, Copa de Sábado"></label>
        <label>Data<input type="date" name="tournament_date" value="{{ old('tournament_date', $event?->tournament_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required></label>
        <label>Formato<select name="format" required>@foreach(['Pauper', 'Commander', 'Standard', 'Modern', 'Draft', 'Outro'] as $option)<option value="{{ $option }}" @selected($format === $option)>{{ $option }}</option>@endforeach</select></label>
        <label>Limite de participantes<input type="number" name="max_players" min="{{ $pod ? 3 : 4 }}" max="128" value="{{ old('max_players', $event?->max_players ?? '') }}" placeholder="Sem limite"><small data-player-limit-hint>Opcional, entre {{ $pod ? 3 : 4 }} e 128</small></label>
    </div>
</x-ui.card>

<x-ui.card title="Disputa" icon="swords">
    <fieldset class="event-group"><legend>Tipo de evento</legend>
        <div class="event-choices is-two">
            @foreach(\App\Models\Tournament::KINDS as $key => [$label, $help])
                <label class="event-choice"><input type="radio" name="kind" value="{{ $key }}" @checked($eventKind === $key)>
                    <x-icon :name="$key === 'tournament' ? 'swords' : 'chart'" class="event-choice-icon" />
                    <span class="event-choice-text"><span class="event-choice-title">{{ $label }}</span><span class="event-choice-desc">{{ $help }}</span></span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <fieldset class="event-group"><legend>Sistema</legend>
        <div class="event-choices is-three">
            @foreach(\App\Models\Tournament::KINDS as $key => [$label, $help, $systems])
                @foreach($systems as $value)
                    @php($commanderOnly = $value === 'pod_elimination')
                    <label class="event-choice" data-system-choice @if($key !== $eventKind) hidden @endif>
                        <input type="radio" name="system" value="{{ $value }}" data-kind="{{ $key }}" @if($commanderOnly) data-commander-only @disabled($format !== 'Commander') @endif @checked($system === $value) required>
                        <span class="event-choice-text"><span class="event-choice-title">{{ \App\Models\Tournament::SYSTEMS[$value] }}</span><span class="event-choice-desc">{{ \App\Models\Tournament::SYSTEM_HINTS[$value] }}</span>@if($commanderOnly)<span class="event-choice-note">Só no formato Commander</span>@endif</span>
                    </label>
                @endforeach
            @endforeach
        </div>
    </fieldset>

    <div class="event-sub" data-rounds-setting @unless(in_array($system, ['swiss', 'commander_pods'], true)) hidden @endunless>
        <label>Rodadas <small>Deixe vazio para calcular pelo número de jogadores (8 jogadores, 3 rodadas).</small><input type="number" name="round_limit" min="1" max="20" value="{{ old('round_limit', $event?->round_limit ?? '') }}" placeholder="Automático"></label>
    </div>

    <div class="event-sub" data-pod-elimination-settings @unless($pod) hidden @endunless>
        <p class="event-sub-title">Eliminação por Pods</p>
        <div class="event-form-grid">
            <label>Classificados por mesa<select name="pod_qualifiers">@foreach([1, 2] as $qualifiers)<option value="{{ $qualifiers }}" @selected((int) old('pod_qualifiers', $event?->pod_qualifiers ?? 2) === $qualifiers)>{{ $qualifiers }}</option>@endforeach</select></label>
            <label>Desempate<select name="pod_tiebreak"><option value="manual" @selected(old('pod_tiebreak', $event?->pod_tiebreak ?? 'manual') === 'manual')>Decisão documentada do organizador</option><option value="playoff" @selected(old('pod_tiebreak', $event?->pod_tiebreak ?? '') === 'playoff')>Partida de desempate documentada</option></select></label>
        </div>
        <input type="hidden" name="pod_random_draw" value="0"><label class="check-label"><input type="checkbox" name="pod_random_draw" value="1" @checked(old('pod_random_draw', $event?->pod_random_draw ?? true))> Sortear as mesas a cada fase</label>
        <input type="hidden" name="pod_consolation" value="0"><label class="check-label"><input type="checkbox" name="pod_consolation" value="1" @checked(old('pod_consolation', $event?->pod_consolation ?? false))> Mesa de consolação para 5º a 8º (só com 8 jogadores e 2 classificados)</label>
        <p class="event-sub-hint">Com 8 jogadores: duas mesas de 4, os 2 melhores de cada uma vão para a mesa final. Só valem divisões exatas em mesas de 3 ou 4, sem BYE; todos precisam fazer check-in.</p>
    </div>

    <label class="ui-option ui-option-box event-ranked"><span class="ui-option-text"><span class="ui-option-title">Evento ranqueado</span><span class="ui-option-desc">Partidas 1v1 valem ELO/PDL. Mesas de Commander não pontuam.</span></span><input type="checkbox" class="ui-switch" role="switch" name="ranked" value="1" @checked(old('ranked', $event?->ranked ?? false)) @disabled($pod)></label>
</x-ui.card>

@include('tournaments.partials.rules-fields')
