{{-- Estante do perfil: troféus e medalhas ganhos em torneios. Até 3 em destaque (maiores, no topo), escolhidos pelo dono com o alfinete. --}}
{{-- Espera $trophies (PrizeService::trophies) e $isOwner. Sem peça nenhuma, a seção não aparece. --}}
@if($trophies['total'] > 0)
@once
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/prizes.css') }}">@endpush
@endonce
<section class="card section-card prize-shelf" id="trofeus" aria-labelledby="prize-shelf-title">
    <div class="section-heading">
        <div><span class="eyebrow">Estante</span><h2 id="prize-shelf-title">Troféus e medalhas <span class="count">{{ $trophies['total'] }}</span></h2></div>
        @if($isOwner)<a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('prizes.index') }}"><x-icon name="gift" />Meus prêmios</a>@endif
    </div>
    @if($isOwner && $trophies['featured']->isEmpty())
        <p class="muted small-note">Use o alfinete para destacar até {{ \App\Models\PrizeAward::SHOWCASE_SLOTS }} peças: elas ficam maiores, no topo da estante.</p>
    @endif
    @if($trophies['featured']->isNotEmpty())
        <ul class="prize-featured">
            @foreach($trophies['featured'] as $award)@include('players.partials.prize-piece', ['award' => $award, 'featured' => true])@endforeach
        </ul>
    @endif
    @if($trophies['others']->isNotEmpty())
        <ul class="prize-pieces">
            @foreach($trophies['others'] as $award)@include('players.partials.prize-piece', ['award' => $award, 'featured' => false])@endforeach
        </ul>
    @endif
</section>
@endif
