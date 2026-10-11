#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Uso: $0 <ticket_hash>" >&2
  exit 1
fi

TICKET_HASH="$1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [[ -f "$TICKET_HASH" ]]; then
  TICKET_PATH="$TICKET_HASH"
else
  TICKET_PATH="${PROJECT_ROOT}/storage/app/private/${TICKET_HASH}"
fi

if [[ ! -f "$TICKET_PATH" ]]; then
  echo "No se encontró el ticket: ${TICKET_HASH}" >&2
  exit 1
fi

OCR_URL="${OCR_OLLAMA_URL:-http://172.17.0.1:11434/api/generate}"
OCR_MODEL="${OCR_OLLAMA_MODEL:-glm-ocr}"
OCR_PROMPT='Analyze receipt and return raw JSON with establishment, category, and items. establishment and category should be strings when identifiable, otherwise empty strings. items must be an array of objects with concept, quantity (default 1), and unit_price. Only raw JSON. No extra text.'

if command -v magick >/dev/null 2>&1; then
  IM_CONVERT=(magick)
elif command -v convert >/dev/null 2>&1; then
  IM_CONVERT=(convert)
else
  echo "No se encontró ImageMagick. Instala el paquete 'imagemagick' en la imagen/host antes de ejecutar OCR." >&2
  exit 1
fi

# 1. Creamos los archivos temporales y los borramos al salir, también si algo falla:
#    contienen la foto del ticket.
TMP_IMG=$(mktemp --suffix=.png)
TMP_PAYLOAD=$(mktemp)
trap 'rm -f "$TMP_PAYLOAD" "$TMP_IMG"' EXIT

# 2. Reducimos solo si es más grande, sin deformar ni rellenar a cuadrado: un ticket
#    largo metido en 1024x1024 quedaba con el texto diminuto.
# Dependiendo de tu versión de ImageMagick, el comando es 'convert' (v6) o 'magick' (v7)
"${IM_CONVERT[@]}" "$TICKET_PATH" \
  -auto-orient \
  -resize '1024x2048>' \
  "$TMP_IMG"

# 3. Convertimos la imagen TEMPORAL a Base64
IMG="$(base64 -w 0 "$TMP_IMG")"
# ------------------------------------------------------

ESCAPED_PROMPT=$(printf '%s' "$OCR_PROMPT" | sed 's/\\/\\\\/g; s/"/\\"/g')
printf '{"model":"%s","prompt":"%s","format":"json","images":["%s"],"stream":false}' \
  "$OCR_MODEL" "$ESCAPED_PROMPT" "$IMG" > "$TMP_PAYLOAD"

# Enviamos el archivo temporal con curl usando -d @
curl -sS --fail-with-body "$OCR_URL" \
  -H "Content-Type: application/json" \
  --data-binary @"$TMP_PAYLOAD"
