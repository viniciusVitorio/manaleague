{{-- Regras do torneio (criar/editar). Os campos personalizados aparecem só com "Personalizadas" marcado (CSS :has). --}}
@php
    $rules = old('rules', $tournament->rules ?? \App\Models\Tournament::RULES_STANDARD);
    $allowBanned = (bool) old('allow_banned', $tournament->allow_banned ?? false);
@endphp
<fieldset class="rules-fieldset">
    <legend>Regras do torneio</legend>
    <label class="rules-option"><input type="radio" name="rules" value="standard" @checked($rules !== 'custom')>
        <span>Padrão do formato<small>Decks com cartas banidas no formato não entram: a inscrição, a troca de deck e o check-in são conferidos. Draft e Outro não têm lista de banidas.</small></span></label>
    <label class="rules-option"><input type="radio" name="rules" value="custom" @checked($rules === 'custom')>
        <span>Personalizadas<small>Você decide se cartas banidas valem e pode escrever as regras da casa.</small></span></label>
    <div class="rules-custom">
        <input type="hidden" name="allow_banned" value="0">
        <label class="rules-option"><input type="checkbox" name="allow_banned" value="1" @checked($allowBanned)>
            <span>Permitir cartas banidas no formato<small>Desmarcado, os decks continuam sendo conferidos como nas regras padrão. Torneios ranqueados não aceitam cartas banidas.</small></span></label>
        <label>Regras da casa <small>Opcional. Aparece no convite e na página pública.</small>
            <textarea name="house_rules" maxlength="1000" placeholder="Ex.: proxies liberados; vale 1 carta de cada coleção nova">{{ old('house_rules', $tournament->house_rules ?? '') }}</textarea></label>
    </div>
</fieldset>
