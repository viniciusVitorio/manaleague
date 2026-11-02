---
name: corrigir-producao
description: Corrige um dado errado em produção do ManaLeague (placar trocado, desafio preso, conquista indevida) com segurança — escreve um script de busca (só leitura) e um de correção que simula antes de gravar, testa no Docker local e entrega o passo a passo SSH com backup do banco. Use quando pedirem para arrumar/ajustar/corrigir algo "em produção", "no servidor" ou "pelo SSH".
---

# Corrigir dado em produção

Produção: EC2 com Docker Compose, release atual em `/opt/manaleague/current`, projeto `manaleague`, serviço `app`,
banco SQLite (modo WAL) em `storage/app/database.sqlite` dentro do container. Você **não** acessa o servidor:
prepara os scripts e o passo a passo, e o dono executa.

## 1. Entender o estrago antes de escrever

- Descubra tudo que depende do dado: placar → estatísticas (calculadas na hora), ordem dos games, feed
  (`community_activities.event_key`), conquistas, ELO (se Ranked: `RatingReplayService`), notificações já enviadas.
- Prefira usar os services e models do app (as mesmas regras do sistema) a SQL solto.
- Se existir um fluxo oficial (contestar placar, moderação do Ranked, `invalidate`), diga ao dono; script é o último recurso.

## 2. Escrever dois scripts (rodam no `php artisan tinker`)

1. **Busca, só leitura** (`<assunto>-find.php`): lista os registros candidatos com id, data, nomes e estado, filtrando por
   variável de ambiente (`SEARCH=...`). Nada de `save()`/`update()`.
2. **Correção** (`<assunto>-fix.php`), recebendo o alvo por variável (`CHALLENGE_ID=...`):
   - imprime **antes → depois** de tudo que vai mudar;
   - sem `APPLY=1`, para aí ("Simulação: nada foi gravado");
   - com `APPLY=1`, grava dentro de `DB::transaction` e mostra o resultado;
   - recusa casos fora do esperado (registro não confirmado, temporada encerrada) sem alterar nada.

Modelo pronto em [exemplos/](exemplos/): `challenge-find.php` e `challenge-swap.php` (inverter placar de desafio casual ou
ranqueado, com replay do ELO, texto do feed e conquistas).

## 3. Testar no Docker local

Recrie a situação com os services (ex.: desafio confirmado com o placar errado), rode busca → simulação → `APPLY=1` → busca
de novo, e confira o resultado:

```bash
docker compose cp fix.php app:/tmp/fix.php
MSYS_NO_PATHCONV=1 docker compose exec -T -e CHALLENGE_ID=10 app php artisan tinker /tmp/fix.php < /dev/null
MSYS_NO_PATHCONV=1 docker compose exec -T -e CHALLENGE_ID=10 -e APPLY=1 app php artisan tinker /tmp/fix.php < /dev/null
```

## 4. Entregar o passo a passo (o dono executa)

```bash
# no PC: enviar os scripts e entrar
scp -i <chave> find.php fix.php <usuario>@<host>:/tmp/
ssh -i <chave> <usuario>@<host>

# no servidor
cd /opt/manaleague/current
# 1) backup consistente (cp não serve com WAL)
sudo docker compose -p manaleague exec -T app php -r '$db = new PDO("sqlite:storage/app/database.sqlite"); $db->exec("VACUUM INTO \"storage/app/backup-" . date("Ymd-His") . ".sqlite\""); echo "backup ok\n";'
# 2) scripts para dentro do container
sudo docker compose -p manaleague cp /tmp/find.php app:/tmp/
sudo docker compose -p manaleague cp /tmp/fix.php app:/tmp/
# 3) achar o registro
sudo docker compose -p manaleague exec -T -e SEARCH=<nome> app php artisan tinker /tmp/find.php
# 4) simular, conferir, aplicar
sudo docker compose -p manaleague exec -T -e CHALLENGE_ID=<id> app php artisan tinker /tmp/fix.php
sudo docker compose -p manaleague exec -T -e CHALLENGE_ID=<id> -e APPLY=1 app php artisan tinker /tmp/fix.php
# 5) limpar
sudo docker compose -p manaleague exec -T app rm /tmp/find.php /tmp/fix.php && rm /tmp/find.php /tmp/fix.php
```

Na resposta, diga: o que muda, o que **não** muda (ex.: notificações antigas continuam no sino), onde fica o backup e como
voltar (restaurar o arquivo de backup com o app parado).

## Não faça

- Rodar nada contra produção você mesmo, nem pedir senha/chave.
- Script que grava sem modo de simulação, ou correção sem backup.
- Apagar registro quando dá para corrigir; apagar conquista especial (concedida por admin).
