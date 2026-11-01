"""
ManaLeague — microsservico de reconhecimento de cartas (visao computacional).

Papel: receber UMA imagem (frame da camera do jogador) e devolver apenas IDs do Scryfall.
Nome, tipo, custo e imagem da carta NUNCA saem daqui: quem resolve isso e o Laravel,
consultando o Scryfall pelo ID (requisito RS17 do projeto). Assim o microsservico nao
se torna uma nova fonte de dados de carta que o navegador possa influenciar.

Isolamento: sem porta publicada (so a rede interna do Docker alcanca), sem acesso ao
banco, sem escrita em disco e com segredo compartilhado obrigatorio (X-Scanner-Token).

O algoritmo (contorno da carta + hash perceptual da arte + busca por distancia de
Hamming no indice) e o do ManaMesh, vendorizado em vendor/manamesh sem alteracoes:
veja scanner/NOTICE.md.
"""

from __future__ import annotations

import asyncio
import hmac
import logging
import os
import sys
from pathlib import Path
from typing import Any, Optional

import cv2
import numpy as np
from fastapi import FastAPI, File, Form, Header, HTTPException, UploadFile
from fastapi.responses import JSONResponse

BASE_DIR = Path(__file__).resolve().parent.parent
# O codigo vendorizado se importa por nome simples ("from card_index import CardIndex"),
# exatamente como no projeto de origem; mantemos os arquivos intactos para facilitar
# atualizar a versao de upstream, e so colocamos a pasta no sys.path.
sys.path.insert(0, str(BASE_DIR / "vendor" / "manamesh"))

from recognizer import CardRecognizer  # noqa: E402  (precisa do sys.path acima)

LOG = logging.getLogger("scanner")

CARD_INDEX_PATH = os.getenv("CARD_INDEX_PATH", str(BASE_DIR / "card_index.npz"))
# Distancia de Hamming maxima (de 256 bits) aceita como acerto. Menor = mais rigoroso
# (menos carta errada, mais "nao reconhecida"). 78 e o valor medido pelo ManaMesh.
MAX_HASH_DISTANCE = int(os.getenv("MAX_HASH_DISTANCE", "78"))
# Segredo compartilhado com o Laravel (CARD_SCANNER_TOKEN). Sem ele o servico nao sobe:
# falhar alto e melhor que subir um endpoint de CPU aberto na rede interna.
SCANNER_TOKEN = os.getenv("SCANNER_TOKEN", "").strip()
# Imagem: limites para nao virar vetor de consumo de CPU/memoria.
MAX_UPLOAD_BYTES = int(os.getenv("MAX_UPLOAD_BYTES", str(6 * 1024 * 1024)))
MAX_IMAGE_SIDE = int(os.getenv("MAX_IMAGE_SIDE", "1920"))
# Reconhecer e sincrono e usa CPU; mais de um por vez na t3.micro so piora a latencia.
_slot = asyncio.Semaphore(int(os.getenv("MAX_CONCURRENT_SCANS", "2")))

app = FastAPI(
    title="ManaLeague Card Scanner",
    description="Reconhece uma carta de Magic em uma imagem e devolve o ID do Scryfall.",
    version="1.0.0",
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
)

_recognizer: Optional[CardRecognizer] = None


def recognizer() -> CardRecognizer:
    global _recognizer
    engine = _recognizer or CardRecognizer(CARD_INDEX_PATH, MAX_HASH_DISTANCE)
    _recognizer = engine
    if engine.index is None:
        raise HTTPException(status_code=503, detail="index_unavailable")

    return engine


@app.on_event("startup")
def startup() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    if not SCANNER_TOKEN:
        raise RuntimeError("SCANNER_TOKEN nao definido: o scanner nao sobe sem o segredo compartilhado.")
    try:
        recognizer()
        LOG.info("indice carregado de %s", CARD_INDEX_PATH)
    except Exception as error:  # noqa: BLE001 - sobe degradado e o /health avisa
        LOG.warning("indice indisponivel: %s", error)


def authorize(token: Optional[str]) -> None:
    """Compara em tempo constante; so o Laravel conhece o segredo."""
    if not token or not hmac.compare_digest(token, SCANNER_TOKEN):
        raise HTTPException(status_code=401, detail="unauthorized")


@app.get("/health")
def health() -> JSONResponse:
    """Usado pelo healthcheck do Docker e pelo app para esconder o botao quando degradado."""
    try:
        stats = recognizer().get_database_stats()
    except Exception as error:  # noqa: BLE001
        return JSONResponse({"status": "degraded", "error": str(error)[:120]}, status_code=503)

    return JSONResponse({"status": "healthy", "entries": stats["total_cards"], "names": stats["unique_names"]})


def decode(payload: bytes) -> np.ndarray:
    image = cv2.imdecode(np.frombuffer(payload, np.uint8), cv2.IMREAD_COLOR)
    if image is None:
        raise HTTPException(status_code=422, detail="invalid_image")

    height, width = image.shape[:2]
    longest = max(height, width)
    if longest > MAX_IMAGE_SIDE:
        scale = MAX_IMAGE_SIDE / longest
        image = cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)

    return image


def ids(result: dict[str, Any]) -> dict[str, Any]:
    """So o que o Laravel precisa: ID, qualidade do acerto e o contorno (para desenhar)."""
    return {
        "scryfall_id": result["scryfall_id"],
        "distance": int(result["distance"]),
        "confidence": float(result.get("confidence") or 0.0),
    }


@app.post("/recognize")
async def recognize(
    image: UploadFile = File(...),
    x: Optional[float] = Form(None, ge=0, le=1),
    y: Optional[float] = Form(None, ge=0, le=1),
    token: Optional[str] = Header(None, alias="X-Scanner-Token"),
) -> dict[str, Any]:
    """
    Reconhece a carta da imagem. Com x/y (fracoes 0-1 de onde o jogador tocou), identifica
    a carta naquele ponto; sem eles, a melhor carta da imagem.

    Respostas: {"status": "match", ...} | {"status": "unsure", "suggestions": [...]} | {"status": "no_match"}
    """
    authorize(token)

    payload = await image.read()
    if not payload:
        raise HTTPException(status_code=422, detail="empty_image")
    if len(payload) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="image_too_large")

    frame = decode(payload)
    engine = recognizer()
    loop = asyncio.get_running_loop()

    async with _slot:
        if x is not None and y is not None:
            height, width = frame.shape[:2]
            result = await loop.run_in_executor(None, engine.recognize_at, frame, x * width, y * height)
        else:
            result = await loop.run_in_executor(None, engine.recognize, frame)

    if result is None:
        return {"status": "no_match"}

    corners = result.get("corners")
    if not result.get("certain", True):
        # Plausivel mas incerto: devolve os palpites para a pessoa confirmar, em vez de
        # arriscar uma carta errada (uma sugestao errada nao custa nada; um acerto falso custa).
        suggestions = [ids(result)] + [ids(alternative) for alternative in result.get("alternatives", [])]
        return {"status": "unsure", "suggestions": suggestions[:3], "corners": corners}

    return {"status": "match", "card": ids(result), "corners": corners}
