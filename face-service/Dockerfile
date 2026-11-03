# ==================================================
# LockSense - Servicio facial (InsightFace + YOLO) para Render
# Va en: face-service/Dockerfile
# En Render: Root Directory = face-service
# ==================================================
FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# Librerias que necesitan InsightFace (compilacion) y OpenCV
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Descargar el modelo facial al construir la imagen (no en cada arranque).
RUN python -c "from insightface.app import FaceAnalysis; \
FaceAnalysis(name='buffalo_l', providers=['CPUExecutionProvider'], allowed_modules=['detection','recognition']).prepare(ctx_id=-1, det_size=(640, 640))"

# Codigo del servicio y modelo YOLO (models/yolov8n.onnx)
COPY . .

# Render asigna el puerto en la variable PORT
CMD ["sh", "-c", "uvicorn app.main:app --host 0.0.0.0 --port ${PORT:-8001}"]