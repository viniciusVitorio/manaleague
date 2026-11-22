@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="card auth-card">
    <span class="eyebrow">Novo organizador</span>
    <h1>Crie sua conta</h1>
    <p class="muted">Após criar a conta, enviaremos um link para confirmar seu e-mail e liberar o acesso ao ManaLeague.</p>
    <form method="POST" action="{{ route('register') }}">@csrf
        <label>Nome<input name="name" value="{{ old('name') }}" maxlength="100" required></label>
        <label>E-mail<input type="email" name="email" value="{{ old('email') }}" required></label>
        <label>Senha<input type="password" name="password" minlength="8" required><small>Minimo de 8 caracteres, com letras e numeros.</small></label>
        <label>Confirmar senha<input type="password" name="password_confirmation" required></label>
        {{-- Vem marcada; depois de um erro no formulário, mantém o que a pessoa escolheu. --}}
        <label class="check-label"><input type="checkbox" name="email_updates" value="1" @checked(! session()->hasOldInput() || old('email_updates'))> Aceito receber e-mails do ManaLeague, como avisos, novidades e estatísticas de partidas.</label>
        <button class="btn btn-primary btn-block">Criar conta</button>
    </form>
    <p class="auth-link">Já possui uma conta? <a href="{{ route('login') }}">Entrar</a></p>
</div></div>
@endsection
