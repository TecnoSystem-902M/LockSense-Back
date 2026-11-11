"""
LockSense — Detector de objetos YOLO (HU7: deteccion de anomalias).

Usa un modelo YOLOv8 exportado a ONNX (yolov8m, yolov8s o yolov8n, el que exista) y
lo ejecuta con onnxruntime (la misma libreria que ya usa InsightFace), sin
instalar PyTorch.

Umbrales de confianza separados:
  - Personas:             YOLO_CONFIANZA_PERSONAS    (por defecto 0.40)
  - Objetos sospechosos:  YOLO_CONFIANZA_SOSPECHOSOS (por defecto 0.25)
  - Demas objetos:        YOLO_CONFIANZA_MINIMA      (por defecto 0.40)
Un cuchillo es delgado y metalico: el modelo casi nunca lo ve con mucha
seguridad, por eso su umbral es mas bajo. Las personas se dejan en 0.40 para
no inventar personas que no estan.

Detecta los 80 objetos del dataset COCO (persona, mochila, celular,
cuchillo, tijeras, etc.). Para LockSense interesa:
  - Cuantas PERSONAS hay frente al casillero.
  - Si aparece algun OBJETO SOSPECHOSO (cuchillo, tijeras, bate).

Fotos oscuras: la ESP32-CAM ve muy mal con poca luz. Si la foto esta oscura
(brillo promedio menor a YOLO_BRILLO_MINIMO, por defecto 90), antes de
analizarla se aclara (correccion gamma + CLAHE) y se corrige el tono verdoso.
Se puede apagar con YOLO_MEJORAR_OSCURAS=false.

Privacidad: no guarda imagenes ni las escribe en logs.
"""

from __future__ import annotations

import logging
import os
from pathlib import Path

import cv2
import numpy as np

logger = logging.getLogger("face-service.yolo")

# Clases del dataset COCO, en el orden del modelo YOLOv8.
CLASES_COCO = [
    "person", "bicycle", "car", "motorcycle", "airplane", "bus", "train", "truck",
    "boat", "traffic light", "fire hydrant", "stop sign", "parking meter", "bench",
    "bird", "cat", "dog", "horse", "sheep", "cow", "elephant", "bear", "zebra",
    "giraffe", "backpack", "umbrella", "handbag", "tie", "suitcase", "frisbee",
    "skis", "snowboard", "sports ball", "kite", "baseball bat", "baseball glove",
    "skateboard", "surfboard", "tennis racket", "bottle", "wine glass", "cup",
    "fork", "knife", "spoon", "bowl", "banana", "apple", "sandwich", "orange",
    "broccoli", "carrot", "hot dog", "pizza", "donut", "cake", "chair", "couch",
    "potted plant", "bed", "dining table", "toilet", "tv", "laptop", "mouse",
    "remote", "keyboard", "cell phone", "microwave", "oven", "toaster", "sink",
    "refrigerator", "book", "clock", "vase", "scissors", "teddy bear",
    "hair drier", "toothbrush",
]

# Objetos que se consideran sospechosos frente a un casillero.
OBJETOS_SOSPECHOSOS = {"knife", "scissors", "baseball bat"}

# Nombres en espanol para los mensajes.
NOMBRES_ES = {
    "person": "persona",
    "knife": "cuchillo",
    "scissors": "tijeras",
    "baseball bat": "bate",
    "backpack": "mochila",
    "handbag": "bolsa",
    "cell phone": "celular",
}

TAMANO_ENTRADA = 640


def _mejorar_si_oscura(imagen: np.ndarray) -> np.ndarray:
    """
    Aclara la foto si esta oscura, para que YOLO distinga mejor:
      1. Correccion gamma (aclara las zonas oscuras sin quemar las claras).
      2. CLAHE sobre la luminosidad (mejora el contraste por zonas).
      3. Balance de grises (quita el tono verde/azul de la ESP32-CAM).
    Si la foto ya tiene buena luz, se regresa igual.
    """
    if os.getenv("YOLO_MEJORAR_OSCURAS", "true").lower() in ("false", "0", "no"):
        return imagen

    try:
        brillo_minimo = float(os.getenv("YOLO_BRILLO_MINIMO", "90"))
    except ValueError:
        brillo_minimo = 90.0

    brillo = float(cv2.cvtColor(imagen, cv2.COLOR_BGR2GRAY).mean())
    if brillo >= brillo_minimo:
        return imagen

    # 1) Gamma: entre mas oscura, mas se aclara.
    gamma = 0.45 if brillo < 45 else (0.6 if brillo < 70 else 0.8)
    tabla = (np.power(np.arange(256) / 255.0, gamma) * 255).clip(0, 255).astype(np.uint8)
    salida = cv2.LUT(imagen, tabla)

    # 2) CLAHE solo sobre la luminosidad (no altera los colores).
    lab = cv2.cvtColor(salida, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    l = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(l)
    salida = cv2.cvtColor(cv2.merge((l, a, b)), cv2.COLOR_LAB2BGR)

    # 3) Balance de grises: iguala el promedio de los 3 canales.
    promedios = salida.reshape(-1, 3).mean(axis=0)
    promedios[promedios == 0] = 1
    salida = (salida * (promedios.mean() / promedios)).clip(0, 255).astype(np.uint8)

    logger.info("Foto oscura (brillo %.0f): se aclaro antes de analizarla.", brillo)
    return salida


class DetectorYolo:
    """Carga el modelo ONNX una sola vez y detecta objetos en imagenes BGR."""

    def __init__(self, ruta_modelo: str, confianza_minima: float = 0.4, iou_nms: float = 0.45):
        import onnxruntime as ort

        self.ruta_modelo = ruta_modelo
        self.iou_nms = iou_nms

        # Umbral por tipo de objeto.
        self.confianza_general = confianza_minima
        self.confianza_personas = _leer_confianza("YOLO_CONFIANZA_PERSONAS", 0.40)
        self.confianza_sospechosos = _leer_confianza("YOLO_CONFIANZA_SOSPECHOSOS", 0.25)

        # Primer filtro: el umbral mas bajo de todos (el de cada clase se
        # aplica despues, en _umbral_de).
        self.confianza_minima = min(
            self.confianza_general, self.confianza_personas, self.confianza_sospechosos
        )

        self.sesion = ort.InferenceSession(ruta_modelo, providers=["CPUExecutionProvider"])
        self.nombre_entrada = self.sesion.get_inputs()[0].name
        self.nombre_modelo = Path(ruta_modelo).stem  # ej. "yolov8n"

        logger.info(
            "Modelo YOLO cargado (CPU): %s | umbrales personas=%.2f sospechosos=%.2f otros=%.2f",
            self.nombre_modelo, self.confianza_personas,
            self.confianza_sospechosos, self.confianza_general,
        )

    def _umbral_de(self, clase: str) -> float:
        """Umbral de confianza que debe pasar cada tipo de objeto."""
        if clase == "person":
            return self.confianza_personas
        if clase in OBJETOS_SOSPECHOSOS:
            return self.confianza_sospechosos
        return self.confianza_general

    # --------------------------------------------------

    def _preparar(self, imagen: np.ndarray) -> tuple[np.ndarray, float, int, int]:
        """Redimensiona sin deformar (letterbox) a 640x640 y normaliza."""
        alto, ancho = imagen.shape[:2]
        escala = min(TAMANO_ENTRADA / alto, TAMANO_ENTRADA / ancho)
        nuevo_ancho, nuevo_alto = int(round(ancho * escala)), int(round(alto * escala))

        redimensionada = cv2.resize(imagen, (nuevo_ancho, nuevo_alto), interpolation=cv2.INTER_LINEAR)

        lienzo = np.full((TAMANO_ENTRADA, TAMANO_ENTRADA, 3), 114, dtype=np.uint8)
        relleno_x = (TAMANO_ENTRADA - nuevo_ancho) // 2
        relleno_y = (TAMANO_ENTRADA - nuevo_alto) // 2
        lienzo[relleno_y:relleno_y + nuevo_alto, relleno_x:relleno_x + nuevo_ancho] = redimensionada

        entrada = cv2.cvtColor(lienzo, cv2.COLOR_BGR2RGB).astype(np.float32) / 255.0
        entrada = np.transpose(entrada, (2, 0, 1))[np.newaxis, ...]  # 1x3x640x640

        return entrada, escala, relleno_x, relleno_y

    def detectar(self, imagen: np.ndarray) -> list[dict]:
        """
        Devuelve una lista de detecciones:
          [{"clase": "person", "confianza": 0.87, "caja": [x1, y1, x2, y2]}, ...]
        Las cajas estan en pixeles de la imagen original.
        """
        imagen = _mejorar_si_oscura(imagen)

        alto, ancho = imagen.shape[:2]
        entrada, escala, relleno_x, relleno_y = self._preparar(imagen)

        salida = self.sesion.run(None, {self.nombre_entrada: entrada})[0]  # 1x84x8400
        predicciones = np.squeeze(salida, axis=0).T                       # 8400x84

        puntajes = predicciones[:, 4:]
        ids_clase = np.argmax(puntajes, axis=1)
        confianzas = puntajes[np.arange(len(ids_clase)), ids_clase]

        mascara = confianzas >= self.confianza_minima
        if not np.any(mascara):
            return []

        cajas = predicciones[mascara, :4]
        confianzas = confianzas[mascara]
        ids_clase = ids_clase[mascara]

        # (cx, cy, w, h) en 640x640  ->  (x, y, w, h) en la imagen original
        cx, cy, w, h = cajas[:, 0], cajas[:, 1], cajas[:, 2], cajas[:, 3]
        x1 = (cx - w / 2 - relleno_x) / escala
        y1 = (cy - h / 2 - relleno_y) / escala
        w_orig = w / escala
        h_orig = h / escala

        cajas_xywh = np.stack([x1, y1, w_orig, h_orig], axis=1)

        # Quitar cajas repetidas del mismo objeto (NMS), por clase.
        resultado: list[dict] = []
        for id_clase in np.unique(ids_clase):
            indices = np.where(ids_clase == id_clase)[0]
            seleccion = cv2.dnn.NMSBoxes(
                cajas_xywh[indices].tolist(),
                confianzas[indices].astype(float).tolist(),
                self.confianza_minima,
                self.iou_nms,
            )
            clase = CLASES_COCO[int(id_clase)] if int(id_clase) < len(CLASES_COCO) else str(int(id_clase))
            umbral = self._umbral_de(clase)

            for k in np.array(seleccion).flatten():
                i = indices[int(k)]

                # Umbral propio de la clase (ej. persona 0.40, cuchillo 0.25)
                if float(confianzas[i]) < umbral:
                    continue

                x, y, cw, ch = cajas_xywh[i]
                resultado.append({
                    "clase": clase,
                    "confianza": round(float(confianzas[i]), 4),
                    "caja": [
                        int(max(0, x)), int(max(0, y)),
                        int(min(ancho, x + cw)), int(min(alto, y + ch)),
                    ],
                })

        resultado.sort(key=lambda d: d["confianza"], reverse=True)
        return resultado

    @staticmethod
    def resumir(detecciones: list[dict]) -> dict:
        """Resumen para LockSense: personas y objetos sospechosos."""
        personas = [d for d in detecciones if d["clase"] == "person"]
        sospechosos = [d for d in detecciones if d["clase"] in OBJETOS_SOSPECHOSOS]

        return {
            "personas": len(personas),
            "confianza_personas": max((d["confianza"] for d in personas), default=0.0),
            "sospechosos": [
                {
                    "clase": d["clase"],
                    "nombre": NOMBRES_ES.get(d["clase"], d["clase"]),
                    "confianza": d["confianza"],
                }
                for d in sospechosos
            ],
        }


def ruta_modelo_por_defecto() -> str:
    """
    Modelo a usar (el primero que exista, en este orden):
      1. Lo que diga YOLO_MODEL_PATH.
      2. face-service/models/yolov8m.onnx  (mediano: el que si reconoce cuchillos)
      3. face-service/models/yolov8s.onnx
      4. face-service/models/yolov8n.onnx
    """
    ruta = os.getenv("YOLO_MODEL_PATH")
    if ruta:
        return ruta

    carpeta = Path(__file__).resolve().parent.parent / "models"
    for nombre in ("yolov8m.onnx", "yolov8s.onnx"):
        candidato = carpeta / nombre
        if candidato.exists():
            return str(candidato)
    return str(carpeta / "yolov8n.onnx")


def _leer_confianza(variable: str, por_defecto: float) -> float:
    try:
        valor = float(os.getenv(variable, str(por_defecto)))
        return valor if 0 < valor < 1 else por_defecto
    except ValueError:
        return por_defecto


def confianza_por_defecto() -> float:
    """Umbral para los objetos que no son personas ni sospechosos."""
    return _leer_confianza("YOLO_CONFIANZA_MINIMA", 0.4)