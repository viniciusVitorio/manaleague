{{-- Atalho −1 / +1 na quantidade de um item da coleção (em 1, o "−" remove o item). --}}
<div class="binder-stepper">
    <form method="POST" action="{{ route('collection.update', $item) }}">@csrf @method('PATCH')<input type="hidden" name="delta" value="-1"><button class="qty-step" aria-label="{{ $item->quantity > 1 ? 'Remover uma cópia de '.$item->name : 'Remover '.$item->name.' da coleção' }}" title="{{ $item->quantity > 1 ? '−1 cópia' : 'Remover da coleção' }}">−</button></form>
    <span class="qty-value" aria-label="{{ $item->quantity }} cópias">{{ $item->quantity }}×</span>
    <form method="POST" action="{{ route('collection.update', $item) }}">@csrf @method('PATCH')<input type="hidden" name="delta" value="1"><button class="qty-step" aria-label="Adicionar uma cópia de {{ $item->name }}" title="+1 cópia">+</button></form>
</div>
