@extends('layouts.app')
{{-- Premiação do torneio: um card por colocação (1º, 2º, 3º, participação). Cada prêmio é uma chave: ligou, aparecem só os campos dele. --}}
{{-- A ordem é a mesma do quadro do torneio: primeiro o troféu ou medalha (colecionável), depois os boosters (prêmio físico). --}}
{{-- No computador os cards ficam em duas colunas: 1º | 2º em cima, 3º | participação embaixo. No celular, um embaixo do outro. --}}
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/prizes.css') }}"><script src="{{ \App\Support\VersionedAsset::url('js/crop-core.js') }}" defer></script><script src="{{ \App\Support\VersionedAsset::url('js/image-crop.js') }}" defer></script><script src="{{ \App\Support\VersionedAsset::url('js/prize-form.js') }}" defer></script>@endpush
@section('content')
@php
    $imageKb = \App\Models\TournamentPrize::IMAGE_MAX_KILOBYTES;
    $imageSide = \App\Models\TournamentPrize::IMAGE_SIZE;
@endphp
<a class="back-link" href="{{ route('tournaments.show', $tournament) }}">← {{ $tournament->name }}</a>
<header class="page-head">
    <div><h1>Premiação</h1><p>Ligue o que cada colocação ganha. Quando o torneio encerrar, os prêmios vão sozinhos para quem ficou em cada lugar. Dá para mudar até lá.</p></div>
</header>

<form method="POST" action="{{ route('tournaments.prizes.update', $tournament) }}" class="prize-form" enctype="multipart/form-data">@csrf @method('PUT')
    @foreach(\App\Models\TournamentPrize::PLACEMENTS as $placement => $label)
        @php
            $booster = $current[$placement]['booster'] ?? null;
            $trophy = $current[$placement]['trophy'] ?? null;
            $everyone = $placement === \App\Models\TournamentPrize::PARTICIPATION;
            $tone = [1 => 'gold', 2 => 'silver', 3 => 'bronze'][$placement] ?? 'plain';
            $trophyOn = (bool) old("prizes.$placement.has_trophy", $trophy !== null);
            $boostersOn = (bool) old("prizes.$placement.has_boosters", $booster !== null);
            // Peça já marcada ao ligar: troféu no pódio, medalha na participação.
            $style = (string) (old("prizes.$placement.style", $trophy?->style) ?: ($everyone ? 'medal' : 'trophy'));
            $boosters = old("prizes.$placement.boosters", $booster?->quantity ?? 1);
            $defaultName = ([1 => 'campeão', 2 => '2º lugar', 3 => '3º lugar'][$placement] ?? 'participação');
            $hasImage = $trophy?->image_path !== null;
        @endphp
        <section class="ui-card prize-form-card is-{{ $tone }}" aria-labelledby="prize-form-title-{{ $placement }}">
            <header class="prize-form-head">
                <h2 id="prize-form-title-{{ $placement }}">{{ $label }}</h2>
                <span>{{ $everyone ? 'Todo inscrito recebe, inclusive o pódio' : 'Vai para quem terminar em '.$label }}</span>
            </header>

            {{-- 1. Colecionável --}}
            <div class="prize-form-part">
                <input type="hidden" name="prizes[{{ $placement }}][has_trophy]" value="0">
                <label class="ui-option">
                    <span class="ui-option-text"><span class="ui-option-title"><x-icon name="trophy" />Troféu ou medalha</span><span class="ui-option-desc">Colecionável: fica no perfil de quem ganhar.</span></span>
                    <input type="checkbox" class="ui-switch" role="switch" name="prizes[{{ $placement }}][has_trophy]" value="1" @checked($trophyOn)>
                </label>
                <div class="prize-form-body">
                    <fieldset class="choice-group"><legend>Peça</legend>
                        <div class="choice-chips" role="radiogroup" aria-label="Peça: {{ $label }}">
                            @foreach(\App\Models\TournamentPrize::STYLES as $key => $name)
                                <label class="choice-chip"><input type="radio" name="prizes[{{ $placement }}][style]" value="{{ $key }}" @checked($style === $key)><span><x-icon :name="$key" />{{ $name }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>
                    <label>Nome <small>Opcional. Sem nome, fica "Troféu de {{ $defaultName }}" (ou a peça escolhida).</small>
                        <input name="prizes[{{ $placement }}][trophy_label]" maxlength="{{ \App\Models\TournamentPrize::LABEL_MAX }}" value="{{ old("prizes.$placement.trophy_label", $trophy?->label) }}" placeholder="Ex.: Taça Liga de Verão">
                    </label>
                    <div class="prize-form-image is-{{ $tone }}" data-prize-image-box data-crop-box>
                        {{-- data-async-keep: a prévia escolhida não some se a página se atualizar sozinha. --}}
                        <div class="prize-form-preview" data-prize-image-preview data-async-keep>
                            @if($hasImage)@include('partials.prize-icon', ['prize' => $trophy, 'size' => 22])@else<span class="prize-icon" aria-hidden="true"><x-icon name="image" :size="22" /></span>@endif
                        </div>
                        <div>
                            <label for="prize-image-{{ $placement }}">Imagem <small>Opcional. Sem imagem, vale o ícone da peça. JPG, PNG ou WebP. Você escolhe o recorte ao selecionar o arquivo; guardamos em {{ $imageSide }}×{{ $imageSide }}.</small></label>
                            <input type="file" id="prize-image-{{ $placement }}" name="images[{{ $placement }}]" accept="image/jpeg,image/png,image/webp" data-prize-image data-max-kb="{{ $imageKb }}" data-crop="contain" data-crop-size="{{ $imageSide }}" data-crop-title="Recortar imagem: {{ $label }}">
                            <small class="prize-form-image-note" data-prize-image-note data-crop-note role="status" aria-live="polite"></small>
                            @if($hasImage)<label class="check-label"><input type="checkbox" name="prizes[{{ $placement }}][remove_image]" value="1"> Remover a imagem atual</label>@endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- 2. Prêmio físico --}}
            <div class="prize-form-part">
                <input type="hidden" name="prizes[{{ $placement }}][has_boosters]" value="0">
                <label class="ui-option">
                    <span class="ui-option-text"><span class="ui-option-title"><x-icon name="gift" />Boosters</span><span class="ui-option-desc">Prêmio da mesa: fica "a receber" até você entregar.</span></span>
                    <input type="checkbox" class="ui-switch" role="switch" name="prizes[{{ $placement }}][has_boosters]" value="1" @checked($boostersOn)>
                </label>
                <div class="prize-form-body">
                    <div class="prize-form-fields">
                        <label>Quantidade{{ $everyone ? ' por inscrito' : '' }}
                            <input type="number" name="prizes[{{ $placement }}][boosters]" min="1" max="{{ \App\Models\TournamentPrize::MAX_BOOSTERS }}" inputmode="numeric" value="{{ $boosters }}">
                        </label>
                        <label>Qual booster <small>Opcional</small>
                            <input name="prizes[{{ $placement }}][booster_label]" maxlength="{{ \App\Models\TournamentPrize::LABEL_MAX }}" value="{{ old("prizes.$placement.booster_label", $booster?->label) }}" placeholder="Ex.: Bloomburrow" list="prize-booster-names">
                        </label>
                    </div>
                </div>
            </div>
        </section>
    @endforeach
    {{-- Quem já digitou um booster numa colocação escolhe o mesmo nas outras. --}}
    <datalist id="prize-booster-names">@foreach($current->pluck('booster.label')->filter()->unique() as $name)<option value="{{ $name }}">@endforeach</datalist>

    <div class="prize-form-actions">
        <a class="ui-btn ui-btn-ghost" href="{{ route('tournaments.show', $tournament) }}">Cancelar</a>
        <button class="ui-btn ui-btn-primary">Salvar premiação</button>
    </div>
</form>
@endsection
