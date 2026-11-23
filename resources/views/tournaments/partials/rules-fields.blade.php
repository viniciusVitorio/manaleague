{{-- Regras do evento (criar/editar). Os campos personalizados aparecem só com "Personalizadas" marcado (CSS :has). --}}
@php
    $rules = old('rules', $tournament->rules ?? \App\Models\Tournament::RULES_STANDARD);
    $allowBanned = (bool) old('allow_banned', $tournament->allow_banned ?? false);
@endphp
<x-ui.card title="Regras do evento" icon="shield" class="event-rules">
    <div class="event-choices is-two" role="radiogroup" aria-label="Regras do evento">
        <label class="event-choice"><input type="radio" name="rules" value="standard" @checked($rules !== 'custom')>
            <span class="event-choice-text"><span class="event-choice-title">Padrão do formato</span><span class="event-choice-desc">Decks com cartas banidas no formato não entram: a inscrição, a troca de deck e o check-in são conferidos. No Pauper, também as cartas que nunca saíram como comum. Draft e Outro não têm lista de banidas.</span></span></label>
        <label class="event-choice"><input type="radio" name="rules" value="custom" @checked($rules === 'custom')>
            <span class="event-choice-text"><span class="event-choice-title">Personalizadas</span><span class="event-choice-desc">Você decide se cartas banidas valem e pode escrever as regras da casa.</span></span></label>
    </div>
    <div class="rules-custom">
        <input type="hidden" name="allow_banned" value="0">
        <label class="ui-option ui-option-box"><span class="ui-option-text"><span class="ui-option-title">Permitir cartas banidas no formato</span><span class="ui-option-desc">No Pauper, libera também as cartas que nunca saíram como comum. Desligado, os decks continuam sendo conferidos como nas regras padrão. Eventos ranqueados não aceitam cartas banidas.</span></span><input type="checkbox" class="ui-switch" role="switch" name="allow_banned" value="1" @checked($allowBanned)></label>
        <label>Regras da casa <small>Opcional. Aparece no convite e na página pública.</small>
            <textarea name="house_rules" maxlength="1000" placeholder="Ex.: proxies liberados; vale 1 carta de cada coleção nova">{{ old('house_rules', $tournament->house_rules ?? '') }}</textarea></label>
    </div>
</x-ui.card>
