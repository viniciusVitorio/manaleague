# Codigo de terceiros vendorizado

`scanner/vendor/manamesh/` contem quatro arquivos copiados **sem alteracao** do projeto
[ManaMesh](https://github.com/KarimLeexz/ManaMesh) (commit `72c99fe`), pasta `backend/`:

| Arquivo | O que faz |
|---|---|
| `card_vision.py` | acha o contorno da carta na imagem, corrige a perspectiva e calcula o hash perceptual de 256 bits da arte |
| `card_index.py` | guarda/carrega o indice (`.npz`) e calcula a distancia de Hamming de uma consulta contra todas as entradas |
| `recognizer.py` | o pipeline de reconhecimento (contorno -> hash -> vizinho mais proximo -> limiar) e o modo "tocar para escanear" |
| `build_index.py` | construtor do indice a partir dos dados bulk do Scryfall |

## Licenca

O README do ManaMesh declara **MIT License** ("feel free to use and modify"). O repositorio
de origem nao traz um arquivo `LICENSE` no commit copiado; a declaracao do README e a
referencia usada aqui. Em caso de uso comercial, confirmar a licenca com o autor.

## Por que vendorizado, e nao um pacote

Nao existe pacote publicado no PyPI. Copiar os quatro arquivos deixa a versao exata fixada
no repositorio (reproduzivel e auditavel pelo Trivy/Sonar) em vez de depender de um `git
clone` no build.

## Regras de manutencao

- **Nao editar** os arquivos desta pasta. Qualquer adaptacao fica em `scanner/app/`.
  Assim, atualizar a versao de upstream e so copiar os arquivos de novo.
- Esta pasta esta fora da analise do Sonar (`sonar.exclusions`), porque nao e codigo do
  projeto e as metricas de qualidade/cobertura descreveriam codigo de terceiros.
- O que *e* codigo do projeto (`scanner/app/`) entra na analise normalmente.
- O indice `card_index.npz` tambem veio do ManaMesh (gerado por `build_index.py` a partir do
  Scryfall, ~5 MB, so hashes — nenhuma imagem de carta). Pode ser reconstruido com
  `python scanner/build_card_index.py`.

## O que ficou de fora de proposito

Do ManaMesh foi aproveitado **apenas o reconhecimento de cartas**. Mesa multiplayer,
Socket.IO, servidor de video (LiveKit), contador de vida, dados/moeda e o frontend dele
nao entraram: o ManaLeague ja tem contador de vida proprio (`LifeCounterService`, que
continua sendo a fonte de verdade da partida) e trazer um servidor de video exigiria
outra instancia EC2.
