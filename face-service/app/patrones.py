"""
LockSense - Detección de patrones de acceso inusuales (HU7, IA_PATRONES).

Usa Isolation Forest (scikit-learn). Por cada petición se entrena un modelo
con el historial del usuario y se evalúa el acceso nuevo. No se guarda ningún
modelo: con unos cientos de accesos el entrenamiento tarda milisegundos.

Datos de cada acceso:
  hora              hora del día en decimal (8.5 = 08:30)
  dia_semana        0 = domingo ... 6 = sábado
  accesos_dia       accesos autorizados ese día, contando este
  fallidos_previos  intentos fallidos en los 10 minutos anteriores
"""

from __future__ import annotations

import math

import numpy as np
from sklearn.ensemble import IsolationForest

MINIMO_HISTORIAL = 20

DIAS = ["domingo", "lunes", "martes", "miércoles", "jueves", "viernes", "sábado"]


def _caracteristicas(acceso: dict) -> list[float]:
    """La hora y el día son cíclicos (23:59 está cerca de 00:00), por eso se
    convierten a seno y coseno."""
    hora = float(acceso["hora"])
    dia = int(acceso["dia_semana"])

    return [
        math.sin(2 * math.pi * hora / 24),
        math.cos(2 * math.pi * hora / 24),
        math.sin(2 * math.pi * dia / 7),
        math.cos(2 * math.pi * dia / 7),
        float(acceso["accesos_dia"]),
        float(acceso["fallidos_previos"]),
    ]


def _formato_hora(hora: float) -> str:
    h = int(hora) % 24
    m = int(round((hora - int(hora)) * 60)) % 60
    return f"{h:02d}:{m:02d}"


def _motivos(historial: list[dict], actual: dict) -> list[str]:
    """Explicación entendible de qué se sale de lo normal."""
    motivos: list[str] = []

    horas = np.array([a["hora"] for a in historial], dtype=float)
    inicio, fin = np.percentile(horas, [10, 90])
    if actual["hora"] < inicio - 0.5 or actual["hora"] > fin + 0.5:
        motivos.append(
            f"Acceso a las {_formato_hora(actual['hora'])}, fuera de su horario habitual "
            f"({_formato_hora(inicio)} a {_formato_hora(fin)})."
        )

    dias = [a["dia_semana"] for a in historial]
    proporcion_dia = dias.count(actual["dia_semana"]) / len(dias)
    if proporcion_dia < 0.05:
        motivos.append(f"Acceso en {DIAS[actual['dia_semana']]}, un día que casi no usa su casillero.")

    accesos = np.array([a["accesos_dia"] for a in historial], dtype=float)
    normal = max(1, int(round(np.percentile(accesos, 90))))
    if actual["accesos_dia"] > normal + 2:
        motivos.append(
            f"{actual['accesos_dia']} accesos en el mismo día (lo normal es hasta {normal})."
        )

    if actual["fallidos_previos"] >= 2:
        motivos.append(
            f"{actual['fallidos_previos']} intentos fallidos justo antes del acceso."
        )

    return motivos


def analizar(historial: list[dict], actual: dict) -> dict:
    """
    Devuelve:
      {"evaluado": False, "motivo": "historial_insuficiente"}   (menos de 20 accesos)
      {"evaluado": True, "anomalo": bool, "puntaje": float, "confianza": float, "motivos": [...]}
    """
    if len(historial) < MINIMO_HISTORIAL:
        return {"evaluado": False, "motivo": "historial_insuficiente"}

    X = np.array([_caracteristicas(a) for a in historial])
    x = np.array([_caracteristicas(actual)])

    # Se entrena con el historial MÁS el acceso nuevo: así, si el acceso nuevo
    # se sale del rango de lo normal, el bosque lo "aísla" con muy pocos cortes.
    modelo = IsolationForest(n_estimators=300, random_state=42)
    modelo.fit(np.vstack([X, x]))

    # score_samples: entre más bajo, más raro. El umbral es el 5% más raro
    # de su PROPIO historial: cada usuario tiene su propia medida de "raro".
    puntajes_historial = modelo.score_samples(X)
    umbral = float(np.percentile(puntajes_historial, 5))
    puntaje = float(modelo.score_samples(x)[0])

    # Para evitar falsas alarmas, solo se reporta si el modelo lo marca
    # Y además hay una razón concreta que explicar al usuario.
    motivos = _motivos(historial, actual) if puntaje < umbral else []
    anomalo = bool(motivos)

    # Confianza de 0.5 a 0.95 según qué tan por debajo del umbral quedó.
    confianza = 0.5 + min(0.45, (umbral - puntaje) / 0.4) if anomalo else 0.0

    return {
        "evaluado": True,
        "anomalo": anomalo,
        "puntaje": round(puntaje, 4),
        "umbral": round(umbral, 4),
        "confianza": round(confianza, 2),
        "motivos": motivos,
    }