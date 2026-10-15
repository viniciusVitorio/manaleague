@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="card auth-card">
    <span class="eyebrow">Recuperar acesso</span>
    <h1>Esqueceu a senha?</h1>
    <p class="muted">Informe o e-mail da sua conta e enviaremos um link para criar uma nova senha.</p>
    <form method="POST" action="{{ route('password.email') }}">@csrf
        <label>E-mail<input type="email" name="email" value="{{ old('email') }}" required autofocus maxlength="255"></label>
        <button class="btn btn-primary btn-block">Enviar link</button>
    </form>
    <p class="auth-link">Lembrou? <a href="{{ route('login') }}">Voltar para o login</a></p>
</div></div>
@endsection
