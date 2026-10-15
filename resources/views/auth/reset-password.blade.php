@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="card auth-card">
    <span class="eyebrow">Recuperar acesso</span>
    <h1>Criar nova senha</h1>
    <p class="muted">Mínimo de 8 caracteres, com letras e números.</p>
    <form method="POST" action="{{ route('password.update') }}">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label>E-mail<input type="email" name="email" value="{{ old('email', $email) }}" required></label>
        <label>Nova senha<input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus></label>
        <label>Confirmar nova senha<input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
        <button class="btn btn-primary btn-block">Salvar nova senha</button>
    </form>
</div></div>
@endsection
