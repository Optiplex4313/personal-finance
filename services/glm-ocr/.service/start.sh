#!/bin/sh
# Entrypoint de glm-ocr: Ollama en loopback + adaptador del contrato OCR en :8000.
# Los ENV del Dockerfile no viajan al runtime: se fijan aquí.
set -eu

export HOME=/root
export OLLAMA_MODELS=/opt/ollama/models
export OLLAMA_HOST=127.0.0.1:11434
export OLLAMA_KEEP_ALIVE="${OLLAMA_KEEP_ALIVE:-30m}"

ollama serve &
exec python3 /usr/local/bin/glm-ocr-adapter
