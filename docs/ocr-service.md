# Servicio OCR

pf-app no lleva OCR dentro. El OCR es un servicio Celaut aparte que se pasa a la app como
paquete `.celaut.bee`. Así la instancia pesa menos y se puede cambiar de OCR sin reconstruir.

## Subir un servicio

Panel > **Servicio OCR**, o por HTTP (sesión de Filament y token CSRF, o `Accept: application/json`):

```
POST /ocr-service            multipart/form-data
  service   archivo *.celaut.bee   (obligatorio)
  name      texto, opcional

201 {"id": "...", "name": "...", "service_hash": "...", "status": "ACTIVE"}
502 {"message": "..."}             el nodo no pudo lanzarlo
DELETE /ocr-service              para el servicio activo (204)
```

Solo hay un servicio activo: subir otro sustituye al anterior (y borra su paquete). Si el
lanzamiento nuevo falla, el anterior sigue activo. Al reiniciar pf-app, `ocr:restore`
vuelve a lanzar el activo.

## Contrato que debe cumplir el servicio

Puerto HTTP expuesto por el servicio (el que declare su `service.json`).

```
POST /ocr
Content-Type: image/jpeg | image/png | ...      cuerpo = la foto del ticket, sin codificar

200 application/json
{
  "establishment": "Mercadona",      cadena; "" si no se identifica
  "category": "Supermercado",        cadena; "" si no se identifica
  "items": [
    {"concept": "Leche", "quantity": 2, "unit_price": 1.10}
  ]
}
```

- `items` es obligatorio y no puede quedar vacío. `quantity` por defecto 1. Admite números o
  cadenas con coma o punto decimal.
- Redimensionar/orientar la imagen y el prompt, si lo hay, son cosa del servicio.
- Cualquier respuesta no 2xx cuenta como fallo del OCR y se muestra en el OcrJob.
- La petición tiene un tope de `OCR_TIMEOUT` segundos (1200 por defecto).

## Servicio de ejemplo

`services/glm-ocr/` define uno que cumple el contrato (Ollama + `glm-ocr`). No se empaqueta
como parte de pf-app.

## Variables

| Variable | Uso |
|---|---|
| `OCR_SERVICE_URL` | Solo desarrollo: URL de un servicio OCR ya levantado. Tiene prioridad sobre el subido. |
| `OCR_TIMEOUT` | Segundos por petición OCR. |
| `OCR_START_TIMEOUT` | Segundos que espera Laravel al lanzamiento (descarga de bloques y arranque). |
| `PF_CONTROL_URL` | La define `pf-entrypoint` (loopback). Sin ella (fuera de un nodo Celaut) no se pueden subir `.celaut.bee`. |

## Cómo se lanza

Laravel guarda el paquete en `storage/app/private/ocr-providers/` y llama al controlador local
de `pf-entrypoint` (`POST /ocr/start`). Ese proceso es el dueño de las instancias hijas:
registra el paquete con `Controller.add_bee_file`, lanza la instancia (la paga el saldo de
pf-app, como pf-postgres) y devuelve `host:puerto`.
