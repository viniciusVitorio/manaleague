# Roteiro de apresentacao — 5 a 7 minutos

## 1. Problema e escopo (40s)

"Construimos o ManaLeague, um sistema que organiza campeonatos de Magic entre amigos. Ele gera uma liga todos-contra-todos, calcula o ranking e cria final e disputa de terceiro lugar. O foco foi integrar seguranca ao ciclo inteiro."

Mostrar requisitos funcionais e de seguranca.

## 2. Ameacas e codigo (1m20s)

Mostrar o modelo STRIDE. Abrir `TournamentPolicy`, `PairingService` e `MatchController` e explicar:

- a policy impede que um organizador altere o torneio de outro;
- jogador e partida precisam pertencer ao torneio da URL;
- o gerador usa transacao e lock para nao duplicar rodadas;
- login e cadastro possuem rate limit.

## 3. Testes (50s)

Executar `php artisan test`. Destacar os testes de isolamento entre organizadores, confrontos sem repeticao, BYE e classificacao.

## 4. Pipeline (1m30s)

Abrir GitHub Actions e explicar a ordem:

1. testes e qualidade;
2. Composer Audit e Trivy;
3. SonarQube e Quality Gate;
4. deploy somente se todos passarem.

Mostrar Sonar com Quality Gate verde. Se houver evidencia de falha anterior, mostrar que o deploy foi pulado.

## 5. Infraestrutura (50s)

Abrir Terraform e AWS:

- EC2 reproduzivel;
- disco criptografado;
- IMDSv2;
- porta 80 publica e SSH limitado ao IP;
- Docker instalado automaticamente.

## 6. Aplicacao online (50s)

Abrir o IP publico, criar uma arena, adicionar quatro jogadores, gerar confrontos e salvar resultados. Mostrar o ranking, gerar final/terceiro lugar e abrir a pagina publica. Finalizar mostrando `/up`.

## Fechamento (20s)

"O principal resultado e que uma mudanca nao chega ao ambiente se quebrar teste, contiver vulnerabilidade critica ou reprovar no Quality Gate. Esse e o principio central do SSDLC aplicado na pratica."
