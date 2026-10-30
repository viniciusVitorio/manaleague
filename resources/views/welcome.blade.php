@extends('layouts.app')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/ranked.css') }}"><link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/landing.css') }}">@endpush
@php
    $title = 'ManaLeague - decks, coleção, partidas e torneios de Magic';
    $og = ['title' => $title, 'description' => 'Monte e teste decks, saiba quanto vale sua coleção, desafie amigos valendo ELO e organize torneios. Seu Magic inteiro num lugar só.'];
    $systems = array_values(\App\Models\Tournament::SYSTEMS);
    $rankedFormats = config('ranked.formats', []);
    $achievementCount = count(\App\Services\AchievementService::definitions());
    $tiers = ['ferro' => 'Ferro', 'bronze' => 'Bronze', 'prata' => 'Prata', 'ouro' => 'Ouro', 'platina' => 'Platina', 'diamante' => 'Diamante', 'mestre' => 'Mestre'];
    $tabs = [['decks', 'Decks'], ['colecao', 'Coleção'], ['mesa', 'Mesa e desafios'], ['ranked', 'Ranked'], ['torneios', 'Torneios'], ['comunidade', 'Comunidade']];
    $audiences = [
        ['user', 'Para quem joga', 'Seu histórico de Magic, finalmente guardado.', [
            'Decks, coleção e lista de compras no mesmo lugar',
            'Estatísticas, sequências e confronto direto com cada amigo',
            'Ranque por formato e conquistas para desbloquear',
        ]],
        ['users', 'Para o grupo de amigos', 'A rivalidade da mesa da cozinha, valendo pontos.', [
            'Desafios 1v1 casuais ou ranqueados',
            'Ranking do grupo, feed e destaques da semana',
            'Troca de cartas: veja quem tem o que falta no seu deck',
        ]],
        ['shield', 'Para lojas e organizadores', 'O evento anda sozinho enquanto você atende o balcão.', [
            implode(', ', array_slice($systems, 0, -1)).' e '.end($systems),
            'Check-in, cronômetro, QR de inscrição e Modo TV',
            'Clube da loja com mural, agenda e ranking acumulado',
        ]],
    ];
    $extras = [
        ['link', 'Perfil com endereço próprio', 'Seu link /p/seu-nome, com foto, vitrine de até 3 decks e temporadas encerradas.'],
        ['sparkles', 'Conquistas compartilháveis', 'Cada conquista ganha um link com prévia para mandar no grupo.'],
        ['lock', 'Você decide o que mostra', 'Decks privados ou visíveis para amigos; o oponente não vê sua lista.'],
        ['bell', 'Notificações sob controle', 'Placar para confirmar, convite, rodada nova. Silencie o que não quiser.'],
        ['moon', 'Tema claro e escuro', 'Pensado para o celular, na mesa, no meio da partida.'],
        ['megaphone', 'Sempre evoluindo', 'Cada versão nova chega com patch notes e um aviso no sino.'],
    ];
@endphp
@section('content')
{{-- HERO --}}
<section class="lp-hero">
    <div class="lp-copy">
        <p class="eyebrow">Para jogadores, grupos e lojas de Magic</p>
        <h1>Seu Magic inteiro, <span>num lugar só.</span></h1>
        <p class="lp-lead">Monte e teste decks, saiba quanto vale sua coleção, desafie amigos valendo ELO e organize torneios da mesa da cozinha ao evento da loja. O ManaLeague guarda cada deck, cada partida e cada conquista.</p>
        <div class="lp-actions">
            @auth
                <a class="ui-btn ui-btn-primary lp-btn" href="{{ route('dashboard') }}">Ir para o painel <x-icon name="arrow-right" :size="16" /></a>
                <a class="ui-btn ui-btn-outline lp-btn" href="{{ route('decks.index') }}"><x-icon name="layers" :size="16" /> Meus decks</a>
            @else
                <a class="ui-btn ui-btn-primary lp-btn" href="{{ route('register') }}">Criar minha conta <x-icon name="arrow-right" :size="16" /></a>
                <a class="ui-btn ui-btn-outline lp-btn" href="{{ route('login') }}">Já tenho conta</a>
            @endauth
        </div>
        <ul class="lp-proof">
            <li><x-icon name="circle-check" :size="14" /> Cartas oficiais do Scryfall</li>
            <li><x-icon name="circle-check" :size="14" /> Feito para o celular</li>
            <li><x-icon name="circle-check" :size="14" /> Sem instalar nada</li>
        </ul>
    </div>

    <figure class="lp-shot lp-profile" aria-label="Exemplo de perfil de jogador">
        <div class="lp-profile-head">
            <i class="lp-av lp-av-lg">VR</i>
            <div>
                <strong>Vini Rodrigues</strong>
                <span class="lp-muted">/p/vini · 12 amigos</span>
            </div>
            <span class="tier-badge tier-ouro">@include('ranked.partials.emblem', ['key' => 'ouro', 'size' => 18])Ouro II</span>
        </div>
        <dl class="lp-stats">
            <div><dt>Vitórias–derrotas</dt><dd>38–17</dd></div>
            <div><dt>Aproveitamento</dt><dd>69%</dd></div>
            <div><dt>Maior sequência</dt><dd>7</dd></div>
        </dl>
        <div class="lp-profile-block">
            <span class="lp-kicker">Vitrine de decks</span>
            <ul class="lp-decks">
                <li><span>Mono-U Faeries</span><small>Pauper</small>@include('partials.color-pips', ['colors' => 'U'])</li>
                <li><span>Atraxa Superfriends</span><small>Commander</small>@include('partials.color-pips', ['colors' => 'WUBG'])</li>
                <li><span>Boros Energy</span><small>Modern</small>@include('partials.color-pips', ['colors' => 'RW'])</li>
            </ul>
        </div>
        <div class="lp-profile-block">
            <span class="lp-kicker">Conquistas · 14/{{ $achievementCount }}</span>
            <div class="lp-trophies" aria-hidden="true"><span title="Champion">👑</span><span title="On Fire">🔥</span><span title="Clean Sweep">🧹</span><span title="Commander Ready">🐉</span><span title="Arco-íris">🌈</span><span title="Playgroup">🍕</span></div>
        </div>
        <div class="lp-toast">
            <x-icon name="circle-check" :size="16" />
            <span><b>Lucas confirmou o placar</b> · Pauper MD3 · 2×1 · <span class="lp-up">+16 ELO</span></span>
        </div>
    </figure>
</section>

<nav class="lp-tabs" aria-label="Funcionalidades">
    @foreach($tabs as [$id, $label])
        <a class="lp-chip" href="#{{ $id }}">{{ $label }}</a>
    @endforeach
</nav>

{{-- PARA QUEM --}}
<section class="lp-section" aria-labelledby="lp-who-title">
    <div class="lp-section-head">
        <p class="eyebrow">Para quem é</p>
        <h2 id="lp-who-title">Do jogador de sexta à loja cheia de mesas.</h2>
    </div>
    <div class="lp-features">
        @foreach($audiences as [$icon, $name, $tagline, $items])
            <article class="lp-feature">
                <div class="lp-feature-title"><x-icon :name="$icon" :size="16" /><h3>{{ $name }}</h3></div>
                <p>{{ $tagline }}</p>
                <ul>
                    @foreach($items as $item)
                        <li><x-icon name="check-check" :size="14" /><span>{{ $item }}</span></li>
                    @endforeach
                </ul>
            </article>
        @endforeach
    </div>
</section>

{{-- DECKS --}}
<section class="lp-split" id="decks" aria-labelledby="lp-decks-title">
    <div class="lp-split-copy">
        <p class="eyebrow">Decks</p>
        <h2 id="lp-decks-title">Monte, teste e chegue com o deck certo.</h2>
        <p>Busque qualquer carta, até pelo nome em português, com dados oficiais do Scryfall. Cole a lista do Arena, MTGO, Moxfield ou LigaMagic e o deck está pronto.</p>
        <ul class="lp-list">
            <li><x-icon name="check-check" :size="14" /><span><b>Simulador de mão</b> com mulligan de Londres e estatística de 1.000 mãos iniciais</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Tag "Banido"</b> nas cartas fora do formato, com a lista oficial atualizada todo dia</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Histórico de versões</b>: cada torneio congela a lista que você jogou</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Comparador</b> entre dois decks, seus ou de amigos que compartilharam</span></li>
            <li><x-icon name="check-check" :size="14" /><span>Sideboard e comandante, capa com a arte da carta e exportação em um clique</span></li>
        </ul>
    </div>
    <figure class="lp-shot" aria-label="Exemplo de deck">
        <div class="lp-shot-head">
            <div><span class="lp-kicker">Pauper · 60 cartas · 15 no side</span><strong>Mono-U Faeries</strong></div>
            @include('partials.color-pips', ['colors' => 'U'])
        </div>
        <ul class="lp-cards">
            <li><b>4</b><span>Spellstutter Sprite</span><span class="lp-cost">@include('partials.mana-cost', ['symbols' => ['1', 'U']])</span></li>
            <li><b>4</b><span>Counterspell</span><span class="lp-cost">@include('partials.mana-cost', ['symbols' => ['U', 'U']])</span></li>
            <li><b>4</b><span>Moon-Circuit Hacker</span><span class="lp-cost">@include('partials.mana-cost', ['symbols' => ['1', 'U']])</span></li>
            <li><b>2</b><span>Gush</span><span class="ui-badge ui-badge-danger">Banido</span></li>
            <li><b>16</b><span>Island</span><span></span></li>
        </ul>
        <div class="lp-hand">
            <span class="lp-kicker">Simulador · 1.000 mãos</span>
            <div class="lp-bars" aria-hidden="true">
                @foreach([['0', 3], ['1', 9], ['2', 27], ['3', 34], ['4', 19], ['5+', 8]] as [$lands, $pct])
                    <div><span><i style="height: {{ round($pct / 34 * 100) }}%"></i></span><small>{{ $lands }}</small></div>
                @endforeach
            </div>
            <span class="lp-muted">Terrenos na mão inicial · 80% das mãos com 2 a 4</span>
        </div>
    </figure>
</section>

{{-- COLECAO --}}
<section class="lp-split is-reverse" id="colecao" aria-labelledby="lp-collection-title">
    <div class="lp-split-copy">
        <p class="eyebrow">Coleção e trocas</p>
        <h2 id="lp-collection-title">Saiba o que você tem, o que falta e quem troca com você.</h2>
        <p>Cadastre suas cartas com edição, condição e foil. O ManaLeague soma o valor estimado da coleção e cruza com os seus decks.</p>
        <ul class="lp-list">
            <li><x-icon name="check-check" :size="14" /><span><b>Quanto falta</b> para montar cada deck, com a lista de compras pronta para colar na loja</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>"Já comprei"</b> joga as cartas que faltavam direto na coleção</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Fichário de trocas</b>: marque o que você topa trocar e veja os amigos que têm o que você procura</span></li>
            <li><x-icon name="check-check" :size="14" /><span>Filtros por cor, tipo, raridade e preço; importe e exporte a lista inteira</span></li>
        </ul>
    </div>
    <figure class="lp-shot" aria-label="Exemplo de coleção">
        <dl class="lp-stats lp-stats-2">
            <div><dt>Cartas na coleção</dt><dd>2.413</dd></div>
            <div><dt>Valor estimado</dt><dd>US$ 1.284</dd></div>
        </dl>
        <div class="lp-row-card">
            <div><span class="lp-kicker">Faltantes · Atraxa Superfriends</span><strong>Faltam 6 cartas</strong></div>
            <span class="ui-btn ui-btn-outline ui-btn-sm" aria-hidden="true"><x-icon name="download" :size="14" /> Lista de compras</span>
        </div>
        <span class="lp-kicker lp-pad">Amigos com cartas da sua lista</span>
        <ul class="lp-feed">
            <li><i class="lp-av">LP</i><span><b>Lucas P.</b> tem 1× Doubling Season para troca</span><small>NM</small></li>
            <li><i class="lp-av">AM</i><span><b>Ana M.</b> tem 2× Teferi's Protection para troca</span><small>LP · foil</small></li>
        </ul>
    </figure>
</section>

{{-- MESA --}}
<section class="lp-split" id="mesa" aria-labelledby="lp-table-title">
    <div class="lp-split-copy">
        <p class="eyebrow">Mesa e desafios</p>
        <h2 id="lp-table-title">Desafie um amigo. O placar fica para sempre.</h2>
        <p>Mande um desafio 1v1 em melhor de 1, 3 ou 5, casual ou valendo ranque. O resultado só conta depois que os dois confirmam. Acabou a discussão.</p>
        <ul class="lp-list">
            <li><x-icon name="check-check" :size="14" /><span><b>Contador de vida</b> com veneno e dano de comandante, que envia o placar no fim da série</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Rivalidades</b>: seu retrospecto contra cada amigo, com botão de revanche</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Confronto direto</b> no perfil, somando torneio, casual e ranked</span></li>
        </ul>
    </div>
    <figure class="lp-shot lp-life" aria-label="Exemplo do contador de vida">
        <div class="lp-life-top"><span class="lp-kicker">Game 2 · Vini 1 × 0 Lucas</span><span class="lp-muted">MD3 · Ranqueada</span></div>
        <div class="lp-life-grid">
            <div class="lp-life-panel is-flipped"><small>Lucas</small><strong>7</strong><span>Veneno 3</span></div>
            <div class="lp-life-panel"><small>Vini</small><strong>14</strong><span>Veneno 0</span></div>
        </div>
        <figcaption>Toque para tirar ou dar vida · no fim da série o placar vai para o Lucas confirmar</figcaption>
    </figure>
</section>

{{-- RANKED --}}
<section class="lp-split is-reverse" id="ranked" aria-labelledby="lp-ranked-title">
    <div class="lp-split-copy">
        <p class="eyebrow">Ranked</p>
        <h2 id="lp-ranked-title">De Ferro a Mestre, temporada após temporada.</h2>
        <p>Cada partida ranqueada vale ELO e PDL no formato jogado: {{ implode(', ', array_slice($rankedFormats, 0, -1)) }} e {{ end($rankedFormats) }}. A temporada fecha com o ranking final no seu perfil e todo mundo recomeça.</p>
        <ul class="lp-list">
            <li><x-icon name="check-check" :size="14" /><span><b>{{ count($tiers) }} patentes</b> com divisões; sobe e cai na hora, sem série de promoção</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Justo de verdade</b>: placar confirmado pelos dois, limite de partidas por dupla e histórico auditável</span></li>
            <li><x-icon name="check-check" :size="14" /><span>Ranking entre amigos e torneios ranqueados que também valem pontos</span></li>
        </ul>
    </div>
    <figure class="lp-shot" aria-label="Exemplo de ranque">
        <div class="lp-rank">
            @include('ranked.partials.emblem', ['key' => 'platina', 'size' => 56])
            <div>
                <span class="lp-kicker">Pauper · Temporada {{ now()->year }}</span>
                <strong>Platina II</strong>
                <span class="lp-muted">1.212 ELO · 14/25 PDL</span>
                <span class="lp-progress" aria-hidden="true"><i style="width: 56%"></i></span>
            </div>
        </div>
        <ul class="lp-deltas">
            <li><span>vs. Lucas P. · 2×1</span><b class="lp-up">+16</b></li>
            <li><span>vs. Ana M. · 0×2</span><b class="lp-down">−12</b></li>
            <li><span>vs. Caio G. · 2×0</span><b class="lp-up">+15</b></li>
        </ul>
        <div class="lp-ladder" aria-label="Patentes">
            @foreach($tiers as $key => $name)
                <span>@include('ranked.partials.emblem', ['key' => $key, 'size' => 26])<small>{{ $name }}</small></span>
            @endforeach
        </div>
    </figure>
</section>

{{-- TORNEIOS --}}
<section class="lp-split" id="torneios" aria-labelledby="lp-tournaments-title">
    <div class="lp-split-copy">
        <p class="eyebrow">Torneios</p>
        <h2 id="lp-tournaments-title">Do convite ao pódio, sem planilha.</h2>
        <p>{{ implode(', ', array_slice($systems, 0, -1)) }} ou {{ end($systems) }}, com final e disputa de terceiro lugar. Regras do formato ou regras da casa. Ninguém fica em volta do organizador.</p>
        <ul class="lp-list">
            <li><x-icon name="check-check" :size="14" /><span><b>Placar pelo celular</b>: o jogador envia, o oponente confirma ou contesta</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Dia do evento</b>: QR de inscrição, check-in, cronômetro da rodada e remoção de ausentes</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Modo TV</b> para o telão e página pública com prévia no WhatsApp</span></li>
            <li><x-icon name="check-check" :size="14" /><span>Decks conferidos na inscrição, retrospectiva do campeão e "repetir torneio" para a semana seguinte</span></li>
        </ul>
    </div>
    <figure class="lp-shot" aria-label="Exemplo da arena de um torneio em andamento">
        <div class="lp-shot-head">
            <div><span class="lp-kicker">Pauper · Suíço</span><strong>Friday Night Magic</strong></div>
            <span class="ui-badge ui-badge-success"><span class="ui-dot"></span>Ao vivo</span>
        </div>
        <div class="lp-preview-bar">
            <span>Rodada 3 de 4</span>
            <span class="lp-timer"><x-icon name="clock" :size="14" /> 38:12</span>
        </div>
        <ol class="lp-standings">
            <li class="lp-standings-head" aria-hidden="true"><span>#</span><span>Jogador</span><span>V-D</span><span>Pts</span></li>
            <li><b>1</b><span><i class="lp-av">VR</i>Vini R.</span><span>2-0</span><strong>6</strong></li>
            <li><b>2</b><span><i class="lp-av">LP</i>Lucas P.</span><span>2-0</span><strong>6</strong></li>
            <li><b>3</b><span><i class="lp-av">AM</i>Ana M.</span><span>1-1</span><strong>3</strong></li>
            <li><b>4</b><span><i class="lp-av">CG</i>Caio G.</span><span>1-1</span><strong>3</strong></li>
        </ol>
        <div class="lp-row-card">
            <div><span class="lp-kicker">Mesa 2</span><span>Ana M. <b>2 × 1</b> Caio G.</span></div>
            <span class="ui-badge ui-badge-warning">Aguardando confirmação</span>
        </div>
    </figure>
</section>

{{-- COMUNIDADE --}}
<section class="lp-split is-reverse" id="comunidade" aria-labelledby="lp-community-title">
    <div class="lp-split-copy">
        <p class="eyebrow">Comunidade</p>
        <h2 id="lp-community-title">O grupo e a loja, juntos num feed.</h2>
        <p>Crie o clube da sua loja ou do seu grupo, aberto ou só com convite. Os torneios do clube entram num ranking acumulado e os membros são avisados.</p>
        <ul class="lp-list">
            <li><x-icon name="check-check" :size="14" /><span><b>Clubes</b> com mural, agenda de torneios e ranking da loja</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>Feed</b> com resultados, campeões, subidas de patente e conquistas dos amigos</span></li>
            <li><x-icon name="check-check" :size="14" /><span><b>{{ $achievementCount }} conquistas</b>, algumas secretas, de "First Blood" a "Dinastia"</span></li>
        </ul>
    </div>
    <figure class="lp-shot" aria-label="Exemplo do feed da comunidade">
        <div class="lp-shot-head">
            <div><span class="lp-kicker">Clube · 34 membros</span><strong>Liga da Quinta</strong></div>
            <span class="ui-badge">Público</span>
        </div>
        <ul class="lp-feed">
            <li><i class="lp-av">AM</i><span><b>Ana M.</b> é a campeã do Friday Night Magic</span><small>👑</small></li>
            <li><i class="lp-av">CG</i><span><b>Caio G.</b> subiu para Diamante II no Modern</span><small>@include('ranked.partials.emblem', ['key' => 'diamante', 'size' => 18])</small></li>
            <li><i class="lp-av">LP</i><span><b>Lucas P.</b> desbloqueou "On Fire": 3 vitórias seguidas</span><small>🔥</small></li>
            <li><i class="lp-av">VR</i><span><b>Vini R.</b> abriu inscrições: Commander de sábado</span><small><x-icon name="calendar" :size="16" /></small></li>
        </ul>
    </figure>
</section>

{{-- E MAIS --}}
<section class="lp-section" aria-labelledby="lp-more-title">
    <div class="lp-section-head">
        <p class="eyebrow">E tem mais</p>
        <h2 id="lp-more-title">Os detalhes que fazem voltar toda semana.</h2>
    </div>
    <div class="lp-extras">
        @foreach($extras as [$icon, $name, $text])
            <article><x-icon :name="$icon" :size="16" /><div><h3>{{ $name }}</h3><p>{{ $text }}</p></div></article>
        @endforeach
    </div>
    <dl class="lp-numbers">
        <div><dd>{{ count($systems) }}</dd><dt>sistemas de torneio</dt></div>
        <div><dd>{{ count($rankedFormats) }}</dd><dt>formatos no ranked</dt></div>
        <div><dd>{{ count($tiers) }}</dd><dt>patentes</dt></div>
        <div><dd>{{ $achievementCount }}</dd><dt>conquistas</dt></div>
    </dl>
</section>

<section class="lp-cta">
    <div>
        <h2>Chame o seu grupo para a próxima.</h2>
        <p>Crie a conta, cadastre o primeiro deck e mande o seu código de amigo para a galera.</p>
    </div>
    @auth
        <a class="ui-btn ui-btn-outline lp-btn" href="{{ route('challenges.index') }}"><x-icon name="swords" :size="16" /> Desafiar um amigo</a>
    @else
        <a class="ui-btn ui-btn-outline lp-btn" href="{{ route('register') }}">Criar minha conta <x-icon name="arrow-right" :size="16" /></a>
    @endauth
</section>

<p class="lp-legal">ManaLeague é conteúdo de fã não oficial, permitido pela <a href="https://company.wizards.com/pt-br/legal/fancontentpolicy" rel="noopener" target="_blank">Política de Conteúdo de Fãs</a>; não é aprovado nem endossado pela Wizards of the Coast. Magic: The Gathering © Wizards of the Coast LLC. Dados e imagens de cartas via <a href="https://scryfall.com" rel="noopener" target="_blank">Scryfall</a>. Valores de coleção são estimativas de referência.</p>
@endsection
