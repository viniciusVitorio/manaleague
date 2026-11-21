{{-- The same copy powers the initial render and format changes, without HTML injection. --}}
@php
    $rules = old('rules', $tournament->rules ?? \App\Models\Tournament::RULES_STANDARD);
    $allowBanned = (bool) old('allow_banned', $tournament->allow_banned ?? false);
    $customRules = $rules === 'custom';
    $rulesCopy = [];
    foreach (['Pauper', 'Commander', 'Standard', 'Modern', 'Draft', 'Outro'] as $rulesFormat) {
        $limited = in_array($rulesFormat, ['Draft', 'Outro'], true);
        $rulesCopy[$rulesFormat] = [
            'summary' => $limited ? $rulesFormat.' não tem lista de banidas no ManaLeague.' : 'A lista de banidas de '.$rulesFormat.' é conferida na inscrição, troca de deck e check-in.'.($rulesFormat === 'Pauper' ? ' Cartas que nunca saíram como comum também não entram.' : ''),
            'rankedBanHint' => $rulesFormat === 'Commander' ? ' Torneios ranqueados não aceitam banidas.' : '',
            'banHint' => $limited ? 'Este formato não tem lista de banidas para liberar.' : 'Desmarcado: a lista de banidas de '.$rulesFormat.' continua valendo.'.($rulesFormat === 'Pauper' ? ' Marcado: libera também cartas que nunca saíram como comum.' : '').($rulesFormat !== 'Commander' ? ' Torneios ranqueados não aceitam banidas.' : ''),
        ];
    }
    $copy = $rulesCopy[$selectedFormat] ?? $rulesCopy['Pauper'];
@endphp
<fieldset class="rules-fieldset" data-rules-copy="{{ json_encode($rulesCopy, JSON_UNESCAPED_UNICODE) }}">
    <legend>Regras do torneio</legend>
    <label class="rules-option"><input type="radio" name="rules" value="standard" @checked(!$customRules)>
        <span>Padrão do formato<small data-rules-summary>{{ $copy['summary'] }}</small></span></label>
    <label class="rules-option"><input type="radio" name="rules" value="custom" @checked($customRules)>
        <span>Personalizadas<small>Defina as regras da casa para este evento.</small></span></label>
    <fieldset class="rules-custom" data-custom-rules @disabled(!$customRules) @if(!$customRules) hidden @endif>
        <input type="hidden" name="allow_banned" value="0">
        <label class="rules-option"><input type="checkbox" name="allow_banned" value="1" @checked($allowBanned)>
            <span>Permitir cartas banidas no formato<small data-ban-hint>{{ $copy['banHint'].(!$multiplayer ? $copy['rankedBanHint'] : '') }}</small></span></label>
        <label>Regras da casa <small>Opcional. Aparece no convite e na página pública.</small>
            <textarea name="house_rules" maxlength="1000" placeholder="Ex.: proxies liberados">{{ old('house_rules', $tournament->house_rules ?? '') }}</textarea></label>
    </fieldset>
</fieldset>
