"""
LockSense — Servicio de reconocimiento facial (embeddings).

Servicio independiente en FastAPI que recibe una imagen, detecta exactamente
un rostro con InsightFace (modelo buffalo_l, CPUExecutionProvider) y devuelve
su embedding de 512 dimensiones. Es consumido por el backend Laravel
(RostroController) mediante POST /v1/embed con el campo multipart `image` y el
header `X-API-Key`.

Contrato (verificado contra LockSense-Back/RostroController.php):
  - Éxito:  {"ok": true, "embedding": [512 floats], "modelo": "<nombre>"}  (HTTP 200)
  - Error:  {"ok": false, "code": "<code>", "message": "..."}             (HTTP 4xx)
    Códigos que Laravel interpreta: invalid_image, no_face, multiple_faces,
    low_quality. Laravel lee el campo JSON `code`.

Privacidad: no se guardan imágenes ni se registran imágenes o embeddings en
los logs; no se envía nada a servicios externos.
"""

from __future__ import annotations

import logging
import os
from contextlib import asynccontextmanager
from pathlib import Path

import cv2
import numpy as np
from fastapi import FastAPI, Header, UploadFile, File
from fastapi.responses import JSONResponse

logger = logging.getLogger("face-service")

# Carga de variables de entorno desde el archivo `.env` del servicio.
#
# El `.env` vive en la RAÍZ de face-service (un nivel por encima de este
# archivo, que está en face-service/app/). La ruta se resuelve a partir de
# `__file__`, por lo que funciona sin importar desde qué directorio se arranque
# el servicio (no depende del CWD).
#
# Fallo seguro: si `python-dotenv` no está instalado o el `.env` no existe, NO
# se interrumpe el arranque ni se inventan valores. El servicio conserva su
# comportamiento: sin FACE_SERVICE_API_KEY en el entorno, rechaza toda petición
# de reconocimiento (ver `_api_key_configurada` y el endpoint /v1/embed).
# Nunca se imprime ni registra el valor de la clave.
_DOTENV_PATH = Path(__file__).resolve().parent.parent / ".env"
try:
    from dotenv import load_dotenv

    # override=False: respeta las variables ya presentes en el entorno real
    # (p. ej. las inyectadas por el sistema o el contenedor) sobre el .env.
    load_dotenv(dotenv_path=_DOTENV_PATH, override=False)
except ImportError:
    logger.warning(
        "python-dotenv no está instalado; no se cargará el archivo .env. "
        "Las variables deben venir del entorno del sistema."
    )

# Dimensión esperada del embedding (ArcFace / buffalo_l).
EMBEDDING_DIM = 512

# Límite de tamaño de imagen por defecto: 8 MB. Configurable por entorno.
DEFAULT_MAX_IMAGE_BYTES = 8 * 1024 * 1024

# Estado del modelo cargado una sola vez al arrancar.
_state: dict[str, object] = {"model": None, "model_name": None}


def _max_image_bytes() -> int:
    """Lee el límite de bytes desde el entorno, con respaldo seguro."""
    raw = os.getenv("FACE_SERVICE_MAX_IMAGE_BYTES")
    if not raw:
        return DEFAULT_MAX_IMAGE_BYTES
    try:
        valor = int(raw)
        return valor if valor > 0 else DEFAULT_MAX_IMAGE_BYTES
    except (TypeError, ValueError):
        return DEFAULT_MAX_IMAGE_BYTES


def _model_name() -> str:
    return os.getenv("FACE_SERVICE_MODEL") or "buffalo_l"


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Inicializa FaceAnalysis UNA sola vez al arrancar (no por solicitud)."""
    # Import diferido: mantiene el arranque de FastAPI legible y evita cargar
    # InsightFace si solo se importa el módulo para pruebas de sintaxis.
    from insightface.app import FaceAnalysis

    nombre = _model_name()
    modelo = FaceAnalysis(name=nombre, providers=["CPUExecutionProvider"])
    # ctx_id=-1 fuerza CPU; det_size estándar de InsightFace.
    modelo.prepare(ctx_id=-1, det_size=(640, 640))

    _state["model"] = modelo
    _state["model_name"] = nombre
    logger.info("Modelo facial inicializado (CPU).")

    try:
        yield
    finally:
        _state["model"] = None
        _state["model_name"] = None


app = FastAPI(title="LockSense Face Service", lifespan=lifespan)


def _error(code: str, message: str, status: int) -> JSONResponse:
    """Respuesta de error controlada con el campo `code` que Laravel espera."""
    return JSONResponse(status_code=status, content={"ok": False, "code": code, "message": message})


def _api_key_configurada() -> str | None:
    key = os.getenv("FACE_SERVICE_API_KEY")
    return key if key else None


@app.get("/health")
async def health():
    """
    Comprobación de estado. No expone información sensible (ni la API key ni
    rutas). Indica si la API responde y si el modelo ya está inicializado.
    """
    return {
        "ok": True,
        "model_loaded": _state["model"] is not None,
    }


@app.post("/v1/embed")
async def embed(
    image: UploadFile = File(...),
    x_api_key: str | None = Header(default=None, alias="X-API-Key"),
):
    # 1) Autenticación. Si la API key no está configurada en el servidor, se
    #    falla de forma segura: NO se atiende ninguna petición de embedding.
    esperada = _api_key_configurada()
    if esperada is None:
        logger.error("FACE_SERVICE_API_KEY no está configurada; se rechaza la solicitud.")
        return _error("unauthorized", "El servicio no está configurado para autenticar.", 503)

    if not x_api_key or not _comparar_claves(x_api_key, esperada):
        return _error("unauthorized", "Clave de API inválida o ausente.", 401)

    # 2) Lectura de bytes y límite de tamaño REAL (no por header HTTP).
    contenido = await image.read()
    if not contenido:
        return _error("invalid_image", "No se recibió ninguna imagen.", 422)

    limite = _max_image_bytes()
    if len(contenido) > limite:
        return _error("invalid_image", "La imagen supera el tamaño máximo permitido.", 422)

    # 3) Decodificación y validación de dimensiones.
    try:
        buffer = np.frombuffer(contenido, dtype=np.uint8)
        imagen = cv2.imdecode(buffer, cv2.IMREAD_COLOR)
    except Exception:
        # No se expone la traza interna al cliente.
        return _error("invalid_image", "No se pudo procesar la imagen.", 422)

    if imagen is None or imagen.size == 0 or imagen.shape[0] < 1 or imagen.shape[1] < 1:
        return _error("invalid_image", "La imagen no es válida.", 422)

    # 4) Detección de rostros con el modelo cargado.
    modelo = _state["model"]
    if modelo is None:
        return _error("unavailable", "El modelo no está disponible.", 503)

    try:
        rostros = modelo.get(imagen)
    except Exception:
        logger.error("Fallo durante la detección facial.")
        return _error("invalid_image", "No se pudo analizar la imagen.", 422)

    # 5) Número de rostros.
    if rostros is None or len(rostros) == 0:
        return _error("no_face", "No se detectó ningún rostro.", 422)
    if len(rostros) > 1:
        return _error("multiple_faces", "Se detectó más de un rostro.", 422)

    # 6) Embedding del único rostro: 512 valores finitos.
    rostro = rostros[0]
    embedding = getattr(rostro, "normed_embedding", None)
    if embedding is None:
        return _error("invalid_image", "No se pudo extraer el embedding.", 422)

    vector = np.asarray(embedding, dtype=np.float64).ravel()

    if vector.shape[0] != EMBEDDING_DIM or not np.all(np.isfinite(vector)):
        # No cumple el contrato (dimensión o valores no finitos).
        return _error("invalid_image", "El embedding generado no es válido.", 422)

    # 7) Respuesta exitosa. Lista JSON de números + nombre del modelo.
    return {
        "ok": True,
        "embedding": [float(x) for x in vector],
        "modelo": str(_state["model_name"] or _model_name()),
    }


def _comparar_claves(entregada: str, esperada: str) -> bool:
    """Comparación en tiempo constante para no filtrar información por timing."""
    import hmac

    return hmac.compare_digest(str(entregada), str(esperada))
