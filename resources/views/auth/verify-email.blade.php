@extends('layouts.app', ['title' => 'Confirme seu e-mail · ManaLeague'])
@section('verification-pending', '1')
@push('head')
<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/email-verification.css') }}">
@endpush
@section('content')
<div class="auth-shell"><section class="card auth-card verification-card" aria-labelledby="verification-title">
    <span class="eyebrow">Falta só confirmar</span>
    <h1 id="verification-title">Confirme seu e-mail</h1>
    <p class="muted">Sua conta foi criada. Para liberar o acesso ao ManaLeague, confirme este endereço pelo link de confirmação:</p>
    <p class="verification-email"><strong>{{ auth()->user()->email }}</strong></p>
    <p class="muted">Confira também o spam. O link expira em {{ config('auth.verification.expire', 60) }} minutos. Se ele venceu ou o e-mail não chegou, peça outro abaixo.</p>
    <div class="verification-actions">
        <form method="POST" action="{{ route('verification.send') }}">@csrf
            <button type="submit" class="ui-btn ui-btn-primary"><x-icon name="inbox" />Reenviar e-mail</button>
        </form>
        <a class="ui-btn ui-btn-outline" href="{{ route('dashboard') }}">Já confirmei meu e-mail</a>
        <form method="POST" action="{{ route('logout') }}">@csrf
            <button type="submit" class="ui-btn ui-btn-ghost">Sair da conta</button>
        </form>
    </div>
    <p class="muted small-note">Você precisa estar conectado à mesma conta ao abrir o link. Usou o endereço errado? Saia e crie uma conta com o e-mail correto.</p>
</section></div>
@endsection
