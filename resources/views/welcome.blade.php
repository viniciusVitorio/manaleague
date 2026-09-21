@extends('layouts.app')
@php($title = 'ManaLeague - torneios sem planilhas')
@section('content')
<section class="landing-hero">
 <div class="landing-copy">
  <span class="landing-badge"><i></i> Feito para comunidades de Magic</span>
  <h1>Seu torneio organizado.<br><span>Do primeiro pareamento ao podio.</span></h1>
  <p>Crie a arena, compartilhe o convite e deixe o ManaLeague cuidar das rodadas, resultados e classificacao em tempo real.</p>
  <div class="landing-actions">
   <a class="btn btn-primary landing-primary" href="{{ route('register') }}">Criar meu torneio <span>&rarr;</span></a>
   <a class="btn btn-ghost" href="{{ route('login') }}">Ja tenho uma conta</a>
  </div>
  <div class="landing-proof"><span>&#10003; Pareamentos automaticos</span><span>&#10003; Placar publico</span><span>&#10003; Convites por link</span></div>
 </div>
 <div class="landing-preview card">
  <div class="preview-top"><div><small>TORNEIO EM ANDAMENTO</small><strong>Friday Night Magic</strong></div><span class="live-pill"><i></i> AO VIVO</span></div>
  <div class="preview-meta"><span>Pauper</span><span>12 jogadores</span><span>Rodada 3 de 5</span></div>
  <div class="preview-table">
   <div class="preview-row preview-head"><span>#</span><span>Jogador</span><span>PTS</span></div>
   <div class="preview-row"><b>1</b><span><i class="avatar">VR</i> Vini Rodrigues</span><strong>9</strong></div>
   <div class="preview-row"><b>2</b><span><i class="avatar">LP</i> Lucas Pereira</span><strong>7</strong></div>
   <div class="preview-row"><b>3</b><span><i class="avatar">AM</i> Ana Martins</span><strong>6</strong></div>
   <div class="preview-row"><b>4</b><span><i class="avatar">CG</i> Caio Gomes</span><strong>6</strong></div>
  </div>
  <div class="preview-foot"><span>Classificacao atualizada automaticamente</span><span>&#9679;</span></div>
 </div>
</section>
<section class="landing-features">
 <article><span class="feature-icon">&nearr;</span><h2>Convide em segundos</h2><p>Envie um link e deixe cada jogador cadastrar nome, deck e cores.</p></article>
 <article><span class="feature-icon">&#8984;</span><h2>Rodadas sem confusao</h2><p>Round-robin, BYE automatico e confrontos sem repeticao.</p></article>
 <article><span class="feature-icon">&#9678;</span><h2>Todo mundo acompanha</h2><p>Ranking e resultados em uma pagina publica bonita e atualizada.</p></article>
</section>
@endsection
