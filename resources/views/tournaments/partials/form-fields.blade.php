@php
    $selectedFormat = old('format', $tournament->format ?? request('format', 'Pauper'));
    $formats = ['Pauper', 'Commander', 'Standard', 'Modern', 'Draft', 'Outro'];
    $selectedFormat = is_string($selectedFormat) && in_array($selectedFormat, $formats, true) ? $selectedFormat : 'Pauper';
    $commander = $selectedFormat === 'Commander';
    $podSystems = ['commander_pods', 'pod_elimination'];
    $legacySystem = isset($tournament) && $tournament->format === 'Commander' && !in_array($tournament->system, $podSystems, true) ? $tournament->system : null;
    $selectedSystem = old('system', $tournament->system ?? ($commander ? 'pod_elimination' : 'round_robin'));
    $elimination = $selectedSystem === 'pod_elimination';
    $multiplayer = in_array($selectedSystem, $podSystems, true);
    $rounds = in_array($selectedSystem, ['swiss', 'commander_pods'], true);
    $advancedErrors = $errors->hasAny(['rules', 'allow_banned', 'house_rules', 'pod_random_draw', 'pod_tiebreak']);
    $advancedOpen = old('rules', $tournament->rules ?? 'standard') === 'custom' || $advancedErrors;
@endphp
@push('head')
<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/tournament-form.css') }}">
<script defer src="{{ \App\Support\VersionedAsset::url('js/pod-elimination-form.js') }}"></script>
@endpush
<section class="card settings-card">
    <div class="settings-card-body">
        <h2>Sobre o torneio</h2>
        <p class="settings-card-desc">Nome, data e formato para o convite.</p>
        <label>Nome do torneio<input name="name" value="{{ old('name', $tournament->name ?? '') }}" maxlength="120" required autofocus></label>
        <div class="tournament-form-grid">
            <label>Data<input type="date" name="tournament_date" value="{{ old('tournament_date', isset($tournament) ? $tournament->tournament_date?->format('Y-m-d') : now()->format('Y-m-d')) }}" required></label>
            <label>Formato<select name="format" required>@foreach(['Pauper','Commander','Standard','Modern','Draft','Outro'] as $format)<option value="{{ $format }}" @selected($selectedFormat === $format)>{{ $format }}</option>@endforeach</select></label>
        </div>
    </div>
</section>
<section class="card settings-card">
    <div class="settings-card-body">
        <h2>Como será a disputa</h2>
        @include('tournaments.partials.competition-fields')
    </div>
</section>
<section class="card settings-card">
    <details data-advanced-settings @if($advancedErrors) data-async-open-errors @endif @if($advancedOpen) open @endif>
        <summary class="tournament-form-summary">Configurações avançadas <span>Regras da casa e critérios da disputa</span></summary>
        <div class="settings-card-body">
            <fieldset data-pod-advanced @disabled(!$elimination) @if(!$elimination) hidden @endif>
                <legend>Mesas e desempates</legend>
                <input type="hidden" name="pod_random_draw" value="0">
                <label class="ui-option ui-option-box"><span>Sortear mesas a cada fase<small>Desmarcado: assentos por nome, sem decidir colocações.</small></span><input class="ui-switch" type="checkbox" name="pod_random_draw" value="1" @checked(old('pod_random_draw', $tournament->pod_random_draw ?? true))></label>
                <label>Desempate<select name="pod_tiebreak"><option value="manual" @selected(old('pod_tiebreak', $tournament->pod_tiebreak ?? 'manual') === 'manual')>Decisão documentada do organizador</option><option value="playoff" @selected(old('pod_tiebreak', $tournament->pod_tiebreak ?? 'manual') === 'playoff')>Partida de desempate documentada</option></select></label>
            </fieldset>
            @include('tournaments.partials.rules-fields')
        </div>
    </details>
</section>
