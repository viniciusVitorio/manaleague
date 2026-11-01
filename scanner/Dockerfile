# Scanner de cartas: FastAPI + OpenCV. Sem porta publicada no compose (so a rede interna
# do Docker alcanca), sem banco e sem escrita em disco.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    CARD_INDEX_PATH=/srv/scanner/card_index.npz

WORKDIR /srv/scanner

# opencv-python-headless nao precisa de libGL (sem GUI); libglib2.0-0 ainda e exigida.
# curl: healthcheck do compose.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libglib2.0-0 curl \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY vendor ./vendor
COPY build_card_index.py ./build_card_index.py
COPY card_index.npz ./card_index.npz

# Usuario sem privilegio: o processo nao precisa escrever nada.
RUN useradd --system --no-create-home --uid 10001 scanner \
    && chown -R root:root /srv/scanner \
    && chmod -R a-w /srv/scanner
USER 10001

EXPOSE 8001

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fs http://127.0.0.1:8001/health >/dev/null || exit 1

# Uma worker: o reconhecimento e CPU-bound e a t3.micro tem 1 vCPU.
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "1", "--no-server-header"]
