#!/usr/bin/env python3
"""Adaptador del contrato OCR de pf-app (docs/ocr-service.md) sobre Ollama + glm-ocr.

POST /ocr  (cuerpo = imagen)  ->  {"establishment", "category", "items": [...]}
Solo biblioteca estándar; usa ImageMagick para orientar y reducir la foto.
"""
import base64
import json
import os
import re
import subprocess
import sys
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

OLLAMA_URL = os.environ.get("OLLAMA_URL", "http://127.0.0.1:11434/api/generate")
OLLAMA_MODEL = os.environ.get("OCR_OLLAMA_MODEL", "glm-ocr")
OLLAMA_TIMEOUT = int(os.environ.get("OCR_OLLAMA_TIMEOUT", "1200"))
PORT = int(os.environ.get("PORT", "8000"))

PROMPT = (
    "Analyze receipt and return raw JSON with establishment, category, and items. "
    "establishment and category should be strings when identifiable, otherwise empty strings. "
    "items must be an array of objects with concept, quantity (default 1), and unit_price. "
    "Only raw JSON. No extra text."
)


def shrink(image: bytes) -> bytes:
    """Orienta y reduce solo si es más grande, sin deformar: un ticket largo en 1024x1024 queda ilegible."""
    for tool in (["magick"], ["convert"]):
        try:
            return subprocess.run(
                [*tool, "-", "-auto-orient", "-resize", "1024x2048>", "png:-"],
                input=image, capture_output=True, check=True,
            ).stdout
        except FileNotFoundError:
            continue
    raise RuntimeError("ImageMagick no está instalado")


def parse_json(text: str) -> dict:
    """El modelo a veces envuelve el JSON en ```json o añade texto alrededor."""
    text = text.strip()
    for candidate in (text, re.sub(r"^```(?:json)?\s*|\s*```$", "", text)):
        try:
            value = json.loads(candidate)
            if isinstance(value, dict):
                return value
        except ValueError:
            pass
    start, end = text.find("{"), text.rfind("}")
    if start != -1 and end > start:
        value = json.loads(text[start:end + 1])
        if isinstance(value, dict):
            return value
    raise ValueError("el modelo no devolvió un JSON válido")


def ocr(image: bytes) -> dict:
    payload = json.dumps({
        "model": OLLAMA_MODEL,
        "prompt": PROMPT,
        "format": "json",
        "images": [base64.b64encode(shrink(image)).decode()],
        "stream": False,
    }).encode()
    request = urllib.request.Request(OLLAMA_URL, payload, {"Content-Type": "application/json"})
    with urllib.request.urlopen(request, timeout=OLLAMA_TIMEOUT) as response:
        decoded = parse_json(json.load(response)["response"])
    return {
        "establishment": str(decoded.get("establishment") or ""),
        "category": str(decoded.get("category") or ""),
        "items": decoded.get("items") or [],
    }


class Handler(BaseHTTPRequestHandler):
    def _reply(self, code: int, body: dict) -> None:
        data = json.dumps(body).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):  # noqa: N802
        if self.path != "/ocr":
            return self._reply(404, {"message": "no encontrado"})
        image = self.rfile.read(int(self.headers.get("Content-Length") or 0))
        if not image:
            return self._reply(400, {"message": "cuerpo vacío: se esperaba una imagen"})
        try:
            self._reply(200, ocr(image))
        except Exception as e:  # noqa: BLE001
            print(f"[adapter] {e!r}", file=sys.stderr, flush=True)
            self._reply(502, {"message": str(e)})


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
