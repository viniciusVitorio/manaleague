# Requisitos

Requisitos levantados antes da implementacao (fase de Requisitos do SSDLC). Cada requisito de seguranca aponta para o controle e o teste que o comprova.

## Funcionais

| ID | Requisito |
|---|---|
| RF01 | Usuario cria conta e faz login com e-mail e senha; cada conta recebe um perfil de jogador. |
| RF02 | Organizador cria, edita (antes do inicio) e exclui torneios com nome, formato, data e limite opcional de jogadores. |
| RF03 | Jogadores entram no torneio por um link de convite; o organizador pode invalidar o link e gerar outro. |
| RF04 | Organizador adiciona amigos (amizade aceita) diretamente ao torneio. |
| RF05 | Com 4+ jogadores o sistema gera a liga todos-contra-todos; numero impar recebe BYE (vitoria 2x0). |
| RF06 | Organizador registra e corrige placares; a classificacao usa pontos, saldo de games, games pro e nome. |
| RF07 | Concluida a liga, o sistema gera final (1o x 2o) e disputa de 3o lugar (3o x 4o); com ambas registradas o torneio e encerrado. |
| RF08 | Pagina publica somente leitura com classificacao, rodadas, podio e retrospectiva do campeao. |
| RF09 | Perfil com decks, estatisticas, historico de torneios e conquistas. |
| RF10 | Solicitacoes de amizade com aceite/recusa; amizade aceita e reciproca. |
| RF11 | Pagina de amigos: busca por apelido ou codigo de amigo, pedidos recebidos/enviados, desfazer amizade, privacidade de busca. |
| RF12 | Recuperacao de senha por e-mail e troca de senha autenticada, com aviso por e-mail. |
| RF13 | Decks com cartas reais (Scryfall): busca, importacao de lista, comandante, sideboard, curva de mana e avisos por formato. |
| RF14 | Conquistas com progresso, data de desbloqueio e raridade. |

## Seguranca

| ID | Requisito | Controle | Evidencia |
|---|---|---|---|
| RS01 | Senhas nunca em texto claro e com complexidade minima | bcrypt (`hashed` cast), `Password::min(8)->letters()->numbers()` | `AuthenticationTest` |
| RS02 | Forca bruta no login/cadastro limitada e registrada | `throttle:5,1`, log `auth.login_failed` | `routes/web.php`, `AuthController` |
| RS03 | Organizador so le/altera os proprios torneios | `TournamentPolicy` em todas as acoes | `TournamentSecurityTest` |
| RS04 | Recursos aninhados pertencem ao torneio da URL (anti-IDOR) | `abort_unless($match->tournament_id === ...)` | `TournamentSecurityTest` |
| RS05 | Todo formulario protegido contra CSRF | middleware `VerifyCsrfToken` sem excecoes | `bootstrap/app.php` |
| RS06 | URL publica e convite nao enumeraveis | sufixo aleatorio de 16 chars no slug, token de 40 chars, `throttle:60,1` na arena | `TournamentIntegrityTest`, `SecurityHeadersTest` |
| RS07 | Resultados nao podem ser adulterados fora da fase correta | liga travada apos as finais; partidas so editaveis na fase delas | `TournamentIntegrityTest` |
| RS08 | Concorrencia nao corrompe o torneio | transacao + `lockForUpdate` na geracao de rodadas e nas inscricoes (limite de vagas) | `PairingService`, `TournamentInviteController` |
| RS09 | Alteracoes de placar sao rastreaveis | log `match.result_saved` com usuario, valor anterior e novo | `MatchController` |
| RS10 | Navegador protegido contra clickjacking, sniffing e injecao de recursos externos | middleware `SecurityHeaders` (CSP, XFO, nosniff, Referrer, COOP, HSTS em HTTPS) | `SecurityHeadersTest` |
| RS11 | Dados pessoais minimos expostos entre usuarios | perfis privados; pedidos de amizade mostram apelido, nunca e-mail | `PlayerProfileTest`, `FriendshipTest` |
| RS12 | Nenhum segredo versionado | `APP_KEY` obrigatoria via ambiente/GitHub Secrets, `.env` ignorado, Trivy no pipeline | `docker-compose.yml`, `.gitignore`, workflow |
| RS13 | Dependencias sem vulnerabilidades conhecidas | `composer audit` + Trivy HIGH/CRITICAL bloqueiam o deploy | `.github/workflows/pipeline.yml` |
| RS14 | Recuperacao de senha nao revela quais e-mails tem conta e nao permite reuso de link | resposta generica, token com hash e expiracao de 60 min, uso unico, throttle | `PasswordResetTest` |
| RS15 | Troca de senha derruba sessoes antigas e avisa o dono | revogacao de sessoes no banco + `PasswordChangedNotification` | `PasswordResetTest` |
| RS16 | Envio de e-mail sem segredo estatico e com menor privilegio | IAM Role na EC2 restrita a `ses:Send*` + `ses:FromAddress`; DKIM/SPF/DMARC | `terraform/ses.tf` |
| RS17 | Dados de carta nao podem ser forjados pelo cliente; imagens so do CDN oficial | servidor busca a carta pelo ID no Scryfall; URLs validadas; CSP `img-src` restrito a `cards.scryfall.io` | `DeckCardsTest` |
| RS18 | Busca de jogadores respeita privacidade e evita spam de pedidos | perfis "so com codigo", minimo de 3 letras, rate limit, maximo de 30 pedidos pendentes | `FriendsPageTest` |
