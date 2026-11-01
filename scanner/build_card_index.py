"""
Constroi o indice de cartas (card_index.npz) a partir do Scryfall.

    python scanner/build_card_index.py                 # build completo (~60k imagens, 30-90 min)
    python scanner/build_card_index.py --limit 500     # teste rapido

O indice e um arquivo de ~5 MB com um hash perceptual de 256 bits por arte de carta
(nenhuma imagem e guardada). E versionado no repositorio, entao o deploy nao depende
desta construcao; rode de novo apenas para incluir colecoes novas.

Este arquivo e so um atalho para o construtor vendorizado em vendor/manamesh.
"""

import argparse
import sys
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(BASE_DIR / "vendor" / "manamesh"))

from build_index import build_index  # noqa: E402  (precisa do sys.path acima)


def main() -> None:
    parser = argparse.ArgumentParser(description="Constroi o indice de hashes de cartas a partir do Scryfall")
    parser.add_argument("--output", "-o", default=str(BASE_DIR / "card_index.npz"), help="Arquivo de saida")
    parser.add_argument("--bulk-file", help="Usa um arquivo bulk local do Scryfall em vez de baixar")
    parser.add_argument("--workers", "-w", type=int, default=8, help="Downloads de imagem em paralelo (padrao: 8)")
    parser.add_argument("--limit", type=int, help="Indexa apenas as primeiras N faces (teste)")
    args = parser.parse_args()

    try:
        build_index(args.output, args.bulk_file, args.workers, args.limit)
    except KeyboardInterrupt:
        print("\nInterrompido — o progresso ficou salvo; rode o mesmo comando de novo para continuar.")


if __name__ == "__main__":
    main()
