# Ranked ELO/PDL

Não há mais uma seção "Ranked" no app: **o ranque aparece no perfil do jogador** (`/me/profile`, bloco "Ranque"), e as partidas ranqueadas nascem de **desafios ranqueados** entre amigos. A moderação fica em `/ranked/admin` (só admin).

## Regras

- **ELO** é o único saldo. **PDL** é só o progresso dentro de uma divisão de 100 pontos.
- Todos começam com **600 (Ferro II, 0/100 PDL)** em cada formato, em cada temporada (regras `elo-v2`). Temporadas que já tinham partidas continuam com a regra com que começaram.
- Cálculo: `E = 1 / (1 + 10^((R_adv − R_seu)/400))`, `Δ = round(K × (S − E))` com S = 1 / 0,5 / 0. O adversário recebe `−Δ`. K = 32, gravado em cada partida.
- Patentes: Ferro (até 799; II até 699) · Bronze · Prata · Ouro · Platina · Diamante (II e I de 100) · Mestre (1800+).
- Só valem desafios marcados como ranqueados **antes** do jogo, com placar enviado por um e confirmado pelo outro. Casuais, BYE, torneios e Commander multiplayer não valem.
- Máximo de 3 ranqueadas por dupla a cada 24h por formato.
- Disputa congela os pontos até a moderação resolver. Invalidação recalcula o formato em ordem e grava lançamentos de correção; nada antigo é editado.
- Privacidade: no perfil, o dono escolhe se o ranque aparece para amigos e organizadores que veem o perfil.

## O que foi removido

Página `/ranked`, regulamento, ranking geral, página de temporada, histórico ranqueado separado e a fila de matchmaking. As tabelas da fila (`matchmaking_entries`, `matchmaking_proposals`) continuam no banco sem uso, para não fazer migração destrutiva.

## Operação

```bash
php artisan ml:admin voce@exemplo.com   # acesso à moderação
php artisan ranked:tick                 # avança temporadas por data
php artisan ranked:prune                # apaga IPs de envios com mais de 90 dias
```

Primeiro uso: `ml:admin` → `/ranked/admin` → criar temporada → **Publicar**.
