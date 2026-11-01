# Scanner de cartas

Microsservico que identifica uma carta de Magic em um frame de camera e devolve **apenas o ID
do Scryfall**. Usado pelo ManaLeague no construtor de deck (adicionar carta) e no contador de
vida (consultar carta durante a partida).

O algoritmo e o do [ManaMesh](https://github.com/KarimLeexz/ManaMesh), vendorizado em
`vendor/manamesh` sem alteracao — veja [NOTICE.md](NOTICE.md). O que e codigo do projeto esta
em `app/`.

## Como funciona

1. o navegador manda um frame JPEG e a posicao do toque (fracoes 0-1) para `POST /cards/scan` no Laravel;
2. o Laravel valida (mimetype, 4 MB, `throttle:20,1`) e repassa para `POST /recognize` aqui, com o segredo compartilhado;
3. aqui: acha o contorno da carta ao redor do toque → corrige a perspectiva → calcula um hash
   perceptual de 256 bits da arte → acha o vizinho mais proximo (distancia de Hamming) no indice;
4. a resposta e `match` (um ID), `unsure` (ate 3 IDs para a pessoa confirmar) ou `no_match`;
5. o Laravel busca os dados oficiais no Scryfall **pelo ID** e so entao devolve a carta ao navegador.

Nome, tipo, custo e imagem nunca saem deste servico: ele nao e fonte de dados de carta.

## Rodar local (sem Docker)

```bash
cd scanner
python -m venv .venv
.venv/Scripts/python -m pip install -r requirements.txt          # Linux/macOS: .venv/bin/python
SCANNER_TOKEN=um-segredo .venv/Scripts/python -m uvicorn app.main:app --port 8001
```

No `.env` do Laravel: `CARD_SCANNER_URL=http://127.0.0.1:8001` e `CARD_SCANNER_TOKEN=um-segredo`.

Conferir que subiu:

```bash
curl http://127.0.0.1:8001/health
# {"status":"healthy","entries":70574,"names":35582}
curl -i -X POST http://127.0.0.1:8001/recognize      # 401: sem o segredo nao responde
```

## Com Docker (como roda em producao)

O servico esta no `docker-compose.yml` sob o perfil `scanner`, sem porta publicada:

```bash
CARD_SCANNER_TOKEN=$(openssl rand -hex 32)   # grave no .env, o mesmo valor para app e scanner
docker compose --profile scanner up -d --build
```

Sem `CARD_SCANNER_TOKEN` a stack sobe normalmente, so que sem o scanner: o botao "Escanear carta"
nao aparece e a rota responde 503.

## Variaveis

| Variavel | Padrao | Para que serve |
|---|---|---|
| `SCANNER_TOKEN` | — (obrigatoria) | segredo compartilhado com o Laravel; sem ela o servico nao sobe |
| `CARD_INDEX_PATH` | `card_index.npz` | caminho do indice |
| `MAX_HASH_DISTANCE` | `78` | distancia de Hamming maxima (de 256 bits) aceita; menor = mais rigoroso |
| `MAX_UPLOAD_BYTES` | `6291456` | tamanho maximo do frame |
| `MAX_IMAGE_SIDE` | `1920` | imagens maiores sao reduzidas antes de processar |
| `MAX_CONCURRENT_SCANS` | `2` | reconhecimentos simultaneos (CPU) |

## Atualizar o indice

`card_index.npz` (~5 MB, so hashes — nenhuma imagem) e versionado no repositorio, entao o deploy
nao precisa construir nada. Para incluir colecoes novas:

```bash
python scanner/build_card_index.py             # 30-90 min, retomavel
python scanner/build_card_index.py --limit 500 # teste rapido
```

Cartas de colecoes que nao estao no indice saem como "nao reconhecida" ou como palpite incerto —
e o sinal de que esta na hora de reconstruir.

## Limites conhecidos

- A edicao devolvida pode nao ser a mesma que esta na sua mao: o indice guarda uma entrada por
  **arte**, entao reimpressoes com a mesma arte caem na mesma entrada. A carta (nome, texto, custo)
  e a certa.
- Foil com reflexo, pouca luz e carta muito inclinada derrubam o acerto; nesse caso o app oferece
  palpites em vez de decidir sozinho.
- A camera exige HTTPS (ou `localhost`) — exigencia do navegador, nao do servico.
