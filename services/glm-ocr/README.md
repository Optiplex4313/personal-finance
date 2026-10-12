# glm-ocr

Servicio OCR de ejemplo para pf-app: Ollama con `glm-ocr` más un adaptador (`adapter.py`)
que cumple el contrato de [docs/ocr-service.md](../../docs/ocr-service.md). Es el OCR que
usaba la app antes de que el servicio se pudiera subir como `.celaut.bee`.

Esta carpeta es la definición; el paquete `.celaut.bee` hay que empaquetarlo con el packer de
Celaut y subirlo en el panel (**Servicio OCR**). Otro modelo: `--build-arg OCR_MODEL=...`.
