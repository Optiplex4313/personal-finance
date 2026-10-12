# glm-ocr — Ollama con el modelo ya incluido + adaptador al contrato OCR de pf-app.
# El modelo se descarga AQUÍ, en el build: dentro de la microVM no hay DNS ni se
# declara red, así que no puede bajarse al arrancar. Solo se exporta el filesystem.
FROM ollama/ollama:0.40.1

ARG OCR_MODEL=glm-ocr
ENV OLLAMA_MODELS=/opt/ollama/models

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 imagemagick \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p "$OLLAMA_MODELS" \
    && (ollama serve >/tmp/ollama-build.log 2>&1 &) \
    && for i in $(seq 1 60); do ollama list >/dev/null 2>&1 && break; sleep 1; done \
    && ollama pull "$OCR_MODEL" \
    && ollama list \
    && pkill ollama || true

COPY adapter.py /usr/local/bin/glm-ocr-adapter
COPY start.sh /usr/local/bin/glm-ocr-start

RUN chmod +x /usr/local/bin/glm-ocr-adapter /usr/local/bin/glm-ocr-start
